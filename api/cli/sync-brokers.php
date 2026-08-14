<?php

/**
 * Auto-sync scheduler entry point. Run by the `scheduler` container
 * (supercronic). Two roles in one script:
 *
 *   php api/cli/sync-brokers.php                     → supervisor
 *   php api/cli/sync-brokers.php --worker [--worker-index=N]  → worker
 *
 * The supervisor sizes a pool of children and aggregates what they report.
 * Each worker runs the exact scheduler that used to be the whole job: it walks
 * the due connections and reserves what it can. No work is split up front —
 * `broker_connections.syncing_since` is what shares it out, so a worker that
 * draws a slow connection never leaves the others idle.
 *
 * Exit codes:
 *   0 — run completed (with or without per-connection failures)
 *   1 — fatal error before the run could complete (bad config, DB down, ...)
 *
 * A flock still guards the SUPERVISOR so a run that overruns its interval does
 * not stack a second pool of children on top of the first. Workers must never
 * take it: they would block on their own parent.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Identifier emitted in every stdout/stderr JSON line so logs from multiple
// scheduled jobs can be filtered/grouped downstream (Railway, Grafana, etc.).
// Convention: kebab-case, matches the CLI filename stem.
const JOB_NAME = 'broker-sync';

use App\Core\Database;
use App\Repositories\AccountRepository;
use App\Repositories\BrokerConnectionRepository;
use App\Repositories\CustomFieldDefinitionRepository;
use App\Repositories\CustomFieldValueRepository;
use App\Repositories\ImportBatchRepository;
use App\Repositories\PositionRepository;
use App\Repositories\SymbolAliasRepository;
use App\Repositories\SymbolRepository;
use App\Repositories\SyncLogRepository;
use App\Repositories\TradeRepository;
use App\Repositories\PlatformSettingsRepository;
use App\Services\Broker\BingxConnector;
use App\Services\Broker\BrokerOpenSyncService;
use App\Services\Broker\BrokerOrderSyncService;
use App\Services\Broker\BrokerSyncSchedulerService;
use App\Services\Broker\BrokerSyncService;
use App\Services\Broker\BrokerSyncSupervisorService;
use App\Services\Broker\CredentialEncryptionService;
use App\Services\Broker\CtraderConnector;
use App\Services\Broker\MetaApiConnector;
use App\Services\Broker\OuinexConnector;
use App\Services\CustomFieldService;
use App\Services\PlatformSettingsService;
use App\Services\Process\ProcOpenProcessPool;
use App\Services\Import\ColumnMapperService;
use App\Services\Import\FileParserService;
use App\Services\Import\ImportService;
use App\Services\Import\RowGroupingService;

/** Default child count when neither the DB setting nor the env var says. */
const DEFAULT_WORKERS = 4;

$argvValues = $argv ?? [];
$isWorker = in_array('--worker', $argvValues, true);
$workerIndex = 0;
foreach ($argvValues as $arg) {
    if (str_starts_with($arg, '--worker-index=')) {
        $workerIndex = max(0, (int) substr($arg, strlen('--worker-index=')));
    }
}

// Load .env (same pattern as seed-demo.php)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (($eq = strpos($line, '=')) === false) continue;
        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === $value[strlen($value) - 1]) {
            $value = substr($value, 1, -1);
        }
        if (!getenv($key)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

// Supervisor only: skip silently if a previous run is still in flight. A worker
// taking this lock would block against its own parent.
$lockHandle = null;
if (!$isWorker) {
    $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'broker-sync.lock';
    $lockHandle = fopen($lockPath, 'c');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fwrite(STDOUT, json_encode([
            'job' => JOB_NAME,
            'status' => 'locked',
            'message' => 'another run in progress',
        ]) . PHP_EOL);
        exit(0);
    }
}

try {
    Database::reset();
    $pdo = Database::getConnection();

    $brokerConnectionRepo = new BrokerConnectionRepository($pdo);

    // Live settings: prefer DB-backed values (admin BO override), fall back to
    // env var, then null. The scheduler skips the run if any required setting
    // is unconfigured (logged with the unconfigured key for debugging).
    $platformSettings = new PlatformSettingsService(new PlatformSettingsRepository($pdo));
    $autoSyncEnabled = $platformSettings->resolve('broker_auto_sync_enabled');
    $syncInterval = $platformSettings->resolve('broker_sync_interval_minutes');
    $maxFailures = $platformSettings->resolve('broker_sync_max_failures');
    $workers = $platformSettings->resolve('broker_sync_workers');
    // Not in the required list below: an unset budget means "no cap", which is
    // the behaviour every provider without a request counter already has.
    $dailyRequestBudget = $platformSettings->resolve('broker_daily_request_budget');

    if ($syncInterval === null || $maxFailures === null) {
        $missing = [];
        if ($syncInterval === null) $missing[] = 'broker_sync_interval_minutes';
        if ($maxFailures === null) $missing[] = 'broker_sync_max_failures';
        fwrite(STDOUT, json_encode([
            'job' => JOB_NAME,
            'status' => 'unconfigured',
            'missing_settings' => $missing,
        ]) . PHP_EOL);
        exit(0);
    }

    if (!$isWorker) {
        // ── Supervisor ──────────────────────────────────────────
        // Only PDO, the connection repository and the settings are wired here:
        // the expensive DI graph belongs to the children.
        $supervisor = new BrokerSyncSupervisorService(
            new ProcOpenProcessPool(),
            $brokerConnectionRepo,
            PHP_BINARY,
            __FILE__,
        );

        $summary = $supervisor->run([
            'auto_sync_enabled' => (bool) $autoSyncEnabled,
            'sync_interval_minutes' => (int) $syncInterval,
            'workers' => $workers === null ? DEFAULT_WORKERS : (int) $workers,
        ]);

        fwrite(STDOUT, json_encode(array_merge(
            ['job' => JOB_NAME, 'status' => 'ok', 'role' => 'supervisor'],
            $summary,
        )) . PHP_EOL);
        exit(0);
    }

    // ── Worker ──────────────────────────────────────────────────
    $brokerConfig = require __DIR__ . '/../config/broker.php';

    // Repositories (all only need PDO)
    $syncLogRepo = new SyncLogRepository($pdo);
    $importBatchRepo = new ImportBatchRepository($pdo);
    $symbolAliasRepo = new SymbolAliasRepository($pdo);
    $symbolRepo = new SymbolRepository($pdo);
    $positionRepo = new PositionRepository($pdo);
    $tradeRepo = new TradeRepository($pdo);
    $accountRepo = new AccountRepository($pdo);
    $customFieldRepo = new CustomFieldDefinitionRepository($pdo);
    $customFieldValueRepo = new CustomFieldValueRepository($pdo);

    // Services
    $customFieldService = new CustomFieldService($customFieldRepo, $customFieldValueRepo);
    $importService = new ImportService(
        new FileParserService(),
        new ColumnMapperService(),
        new RowGroupingService(),
        $importBatchRepo,
        $symbolAliasRepo,
        $symbolRepo,
        $positionRepo,
        $tradeRepo,
        $accountRepo,
        $pdo,
        $customFieldService,
    );

    $crypto = new CredentialEncryptionService($brokerConfig['encryption_key']);
    // The scheduler resolves credentials the same way the HTTP path does:
    // connection row plus the user's shared app credentials (docs/91). It also
    // writes a refreshed cTrader access token back to the shared row, so the
    // user's other connections do not each burn a refresh call of their own.
    $brokerCredentialStore = new \App\Services\Broker\BrokerCredentialStore(
        new \App\Repositories\BrokerCredentialRepository($pdo),
        $crypto,
        new \App\Services\Broker\BrokerCredentialMapper(),
    );
    $metaApiConnector = new MetaApiConnector(
        new \GuzzleHttp\Client(),
        $brokerConfig['metaapi']['base_url']
    );
    $ctraderConnector = new CtraderConnector($brokerConfig['ctrader']);
    $ouinexConnector = new OuinexConnector(
        new \GuzzleHttp\Client(),
        $brokerConfig['ouinex']['graphql_url']
    );
    $bingxConnector = new BingxConnector(
        new \GuzzleHttp\Client(),
        $brokerConfig['bingx']['base_url']
    );
    $partialExitRepo = new \App\Repositories\PartialExitRepository($pdo);
    $brokerOpenSyncService = new BrokerOpenSyncService($positionRepo, $tradeRepo, $partialExitRepo);
    $orderRepo = new \App\Repositories\OrderRepository($pdo);
    $brokerOrderSyncService = new BrokerOrderSyncService($orderRepo, $positionRepo);

    $syncService = new BrokerSyncService(
        $brokerConnectionRepo,
        $syncLogRepo,
        $importService,
        new RowGroupingService(),
        $brokerCredentialStore,
        $ctraderConnector,
        $metaApiConnector,
        $ouinexConnector,
        $bingxConnector,
        $brokerOpenSyncService,
        $brokerOrderSyncService,
        $accountRepo,
        // Without it the scheduler writes broker timestamps in UTC while a
        // manual sync writes them in the user's timezone — the same position
        // would drift by the offset depending on which path last touched it.
        new \App\Repositories\UserRepository($pdo),
        // Admin BO setting first, env var second, then the config default.
        (int) ($dailyRequestBudget ?? $brokerConfig['daily_request_budget']),
        // Handed to the reservation so a connection is synced at most once per
        // tick: every worker walks the same due list, and without this the one
        // arriving second re-syncs what the first has just finished.
        (int) $syncInterval,
    );

    $scheduler = new BrokerSyncSchedulerService(
        $brokerConnectionRepo,
        $syncService,
        [
            'auto_sync_enabled' => (bool) $autoSyncEnabled,
            'sync_interval_minutes' => (int) $syncInterval,
            'max_consecutive_failures' => (int) $maxFailures,
            // Staggers this worker's scan so the pool does not pile onto the
            // head of the due list.
            'worker_index' => $workerIndex,
        ],
    );

    $summary = $scheduler->runDueConnections();

    fwrite(STDOUT, json_encode(array_merge(
        ['job' => JOB_NAME, 'status' => 'ok', 'role' => 'worker', 'worker_index' => $workerIndex],
        $summary,
    )) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode([
        'job' => JOB_NAME,
        'status' => 'error',
        'role' => $isWorker ? 'worker' : 'supervisor',
        'message' => $e->getMessage(),
    ]) . PHP_EOL);
    exit(1);
} finally {
    if (isset($lockHandle) && is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
