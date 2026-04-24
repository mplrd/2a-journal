<?php

/**
 * Auto-sync scheduler entry point. Run by the `scheduler` container
 * (supercronic) every 5 minutes. Looks up all ACTIVE broker connections
 * whose last sync is older than BROKER_SYNC_INTERVAL_MINUTES and syncs them.
 *
 * Usage: php api/cli/sync-brokers.php
 *
 * Exit codes:
 *   0 — run completed (with or without per-connection failures)
 *   1 — fatal error before the run could complete (bad config, DB down, ...)
 *
 * A flock prevents two overlapping runs from stepping on each other when the
 * cron fires while a previous run is still going.
 */

require_once __DIR__ . '/../vendor/autoload.php';

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
use App\Services\Broker\BrokerSyncSchedulerService;
use App\Services\Broker\BrokerSyncService;
use App\Services\Broker\CredentialEncryptionService;
use App\Services\Broker\CtraderConnector;
use App\Services\Broker\MetaApiConnector;
use App\Services\CustomFieldService;
use App\Services\Import\ColumnMapperService;
use App\Services\Import\FileParserService;
use App\Services\Import\ImportService;
use App\Services\Import\RowGroupingService;

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

// Acquire lock — skip silently if a previous run is still in flight.
$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'broker-sync.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, json_encode([
        'status' => 'locked',
        'message' => 'another run in progress',
    ]) . PHP_EOL);
    exit(0);
}

try {
    Database::reset();
    $pdo = Database::getConnection();

    $brokerConfig = require __DIR__ . '/../config/broker.php';

    // Repositories (all only need PDO)
    $brokerConnectionRepo = new BrokerConnectionRepository($pdo);
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
    $metaApiConnector = new MetaApiConnector(
        new \GuzzleHttp\Client(),
        $brokerConfig['metaapi']['base_url']
    );
    $ctraderConnector = new CtraderConnector($brokerConfig['ctrader']);

    $syncService = new BrokerSyncService(
        $brokerConnectionRepo,
        $syncLogRepo,
        $importService,
        new RowGroupingService(),
        $crypto,
        $ctraderConnector,
        $metaApiConnector,
    );

    $scheduler = new BrokerSyncSchedulerService(
        $brokerConnectionRepo,
        $syncService,
        [
            'auto_sync_enabled' => (bool) $brokerConfig['auto_sync_enabled'],
            'sync_interval_minutes' => (int) $brokerConfig['sync_interval_minutes'],
            'max_consecutive_failures' => (int) $brokerConfig['max_consecutive_failures'],
        ],
    );

    $summary = $scheduler->runDueConnections();

    fwrite(STDOUT, json_encode(array_merge(['status' => 'ok'], $summary)) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]) . PHP_EOL);
    exit(1);
} finally {
    if (isset($lockHandle) && is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
