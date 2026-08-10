<?php

namespace Tests\Unit\Services\Broker;

use App\Enums\BrokerProvider;
use App\Enums\ConnectionStatus;
use App\Enums\SyncStatus;
use App\Repositories\BrokerConnectionRepository;
use App\Repositories\BrokerCredentialRepository;
use App\Repositories\SyncLogRepository;
use App\Services\Broker\BrokerCredentialMapper;
use App\Services\Broker\BrokerCredentialStore;
use App\Services\Broker\BrokerOpenSyncService;
use App\Services\Broker\BrokerOrderSyncService;
use App\Services\Broker\BrokerSyncService;
use App\Services\Broker\ConnectorInterface;
use App\Services\Broker\CredentialEncryptionService;
use App\Services\Import\ImportService;
use App\Services\Import\RowGroupingService;
use PHPUnit\Framework\TestCase;

class BrokerSyncServiceTest extends TestCase
{
    private BrokerSyncService $service;
    private BrokerConnectionRepository $connectionRepo;
    private SyncLogRepository $syncLogRepo;
    private ImportService $importService;
    private CredentialEncryptionService $crypto;
    private BrokerCredentialRepository $credentialRepo;
    private BrokerCredentialStore $credentialStore;
    private ConnectorInterface $metaApiConnector;
    private ConnectorInterface $ctraderConnector;
    private ConnectorInterface $ouinexConnector;
    private ConnectorInterface $bingxConnector;
    private BrokerOpenSyncService $openSyncService;
    private BrokerOrderSyncService $orderSyncService;

    protected function setUp(): void
    {
        $this->connectionRepo = $this->createMock(BrokerConnectionRepository::class);
        $this->syncLogRepo = $this->createMock(SyncLogRepository::class);
        $this->importService = $this->createMock(ImportService::class);
        $this->crypto = new CredentialEncryptionService(random_bytes(32));
        // No shared row by default, so forConnection() resolves to exactly the
        // connection blob these tests seed — the pre-sharing behaviour.
        $this->credentialRepo = $this->createMock(BrokerCredentialRepository::class);
        $this->credentialStore = new BrokerCredentialStore(
            $this->credentialRepo,
            $this->crypto,
            new BrokerCredentialMapper(),
        );
        $this->metaApiConnector = $this->createMock(ConnectorInterface::class);
        $this->ctraderConnector = $this->createMock(ConnectorInterface::class);
        $this->ouinexConnector = $this->createMock(ConnectorInterface::class);
        $this->bingxConnector = $this->createMock(ConnectorInterface::class);
        $this->openSyncService = $this->createMock(BrokerOpenSyncService::class);
        $this->orderSyncService = $this->createMock(BrokerOrderSyncService::class);

        // Every nominal sync reserves the connection first. Tests that exercise
        // a REFUSED reservation build their own repository mock: PHPUnit picks
        // the first registered matcher, so this default can't be overridden.
        $this->connectionRepo->method('claimForSync')->willReturn(true);

        $this->service = new BrokerSyncService(
            $this->connectionRepo,
            $this->syncLogRepo,
            $this->importService,
            new RowGroupingService(),
            $this->credentialStore,
            $this->ctraderConnector,
            $this->metaApiConnector,
            $this->ouinexConnector,
            $this->bingxConnector,
            $this->openSyncService,
            $this->orderSyncService,
        );
    }

    /**
     * Tests that don't care about the live snapshot paths still need the
     * connector and diff services to return something sensible so the sync
     * doesn't blow up on null/array mismatch. Helper keeps that boilerplate
     * out of the legacy test bodies. Tests that DO care declare their own
     * ->expects(...) BEFORE calling this — first matcher wins in PHPUnit so
     * we don't shadow them via setUp.
     */
    private function stubOpenSnapshotDefaults(ConnectorInterface $connector): void
    {
        $connector->method('fetchOpenPositions')
            ->willReturn(['positions' => [], 'raw_count' => 0]);
        $connector->method('fetchOpenOrders')
            ->willReturn(['orders' => [], 'raw_count' => 0]);
        $connector->method('fetchClosedOrders')
            ->willReturn(['orders' => [], 'raw_count' => 0]);
        $this->openSyncService->method('apply')
            ->willReturn(['inserted' => 0, 'updated' => 0, 'transitioned' => 0, 'skipped_orphans' => 0]);
        $this->orderSyncService->method('apply')
            ->willReturn(['inserted' => 0, 'updated' => 0, 'executed' => 0, 'expired' => 0, 'cancelled' => 0]);
    }

    private function makeConnection(string $provider = 'METAAPI', array $credentials = []): array
    {
        $creds = $credentials ?: ['api_token' => 'test', 'metaapi_account_id' => 'acc-1'];
        $encrypted = $this->crypto->encrypt($creds);

        return [
            'id' => 1,
            'user_id' => 10,
            'account_id' => 5,
            'provider' => $provider,
            'status' => ConnectionStatus::ACTIVE->value,
            'credentials_encrypted' => $encrypted['ciphertext'],
            'credentials_iv' => $encrypted['iv'],
            'last_sync_at' => null,
            'sync_cursor' => null,
        ];
    }

    public function testSyncCallsConnectorAndImportsPositions(): void
    {
        $connection = $this->makeConnection();
        $this->stubOpenSnapshotDefaults($this->metaApiConnector);

        $this->connectionRepo->method('findById')->willReturn($connection);
        $this->syncLogRepo->method('create')->willReturn(['id' => 1]);

        $deals = [
            [
                'symbol' => 'GER40', 'direction' => 'BUY', 'entry_price' => 19200,
                'exit_price' => 19226, 'size' => 1.0, 'pnl' => 26.0,
                'opened_at' => '2024-11-22 07:43:00', 'closed_at' => '2024-11-22 07:44:00',
                'external_id' => 'metaapi_pos-100', 'pips' => null, 'comment' => null,
            ],
        ];

        $this->metaApiConnector->method('refreshCredentials')
            ->willReturnArgument(0);
        $this->metaApiConnector->method('fetchDeals')
            ->willReturn(['deals' => $deals, 'cursor' => '2024-11-22T07:44:00Z', 'raw_count' => 2]);

        $this->importService->expects($this->once())
            ->method('importNormalizedPositions')
            ->willReturn([
                'batch_id' => 1, 'imported_positions' => 1, 'imported_trades' => 1,
                'skipped_duplicates' => 0, 'skipped_errors' => 0, 'errors' => [],
            ]);

        $this->connectionRepo->expects($this->atLeastOnce())->method('update');
        $this->syncLogRepo->expects($this->atLeastOnce())->method('update');

        $result = $this->service->sync(1, 10);

        $this->assertSame(1, $result['imported_positions']);
        $this->assertSame(SyncStatus::SUCCESS->value, $result['status']);
    }

    public function testSyncRejectsWrongUser(): void
    {
        $connection = $this->makeConnection();
        $this->connectionRepo->method('findById')->willReturn($connection);

        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->service->sync(1, 999); // wrong user
    }

    public function testSyncRejectsInactiveConnection(): void
    {
        $connection = $this->makeConnection();
        $connection['status'] = ConnectionStatus::REVOKED->value;
        $this->connectionRepo->method('findById')->willReturn($connection);

        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->service->sync(1, 10);
    }

    // ── Shared credentials and the token refresh (docs/91) ──────────

    public function testSyncSkipsTheRefreshWhenTheSharedTokenWasJustRenewed(): void
    {
        // cTrader rotates the refresh token on use. Once two connections share
        // one, a second sync running moments after the first would present a
        // token the first has already consumed — the refresh throws, the sync
        // fails, and the connection flips to ERROR. Sharing created that race;
        // this is what closes it. It also spends one refresh call instead of
        // one per connection, which is evolution #22's whole subject.
        $connection = $this->makeConnection('CTRADER', [
            'access_token' => 'tok', 'refresh_token' => 'ref', 'ctid_trader_account_id' => 123,
        ]);
        $this->stubOpenSnapshotDefaults($this->ctraderConnector);
        $this->connectionRepo->method('findById')->willReturn($connection);
        $this->syncLogRepo->method('create')->willReturn(['id' => 1]);

        // Another connection renewed the shared credentials seconds ago.
        $this->credentialRepo->method('secondsSinceUpdate')->willReturn(4);

        $this->ctraderConnector->expects($this->never())->method('refreshCredentials');
        $this->ctraderConnector->method('fetchDeals')
            ->willReturn(['deals' => [], 'cursor' => null, 'raw_count' => 0]);
        $this->importService->method('importNormalizedPositions')->willReturn([
            'batch_id' => 1, 'imported_positions' => 0, 'imported_trades' => 0,
            'skipped_duplicates' => 0, 'skipped_errors' => 0, 'errors' => [],
        ]);

        $this->service->sync(1, 10);
    }

    public function testSyncStillRefreshesWhenTheSharedTokenIsOld(): void
    {
        $connection = $this->makeConnection('CTRADER', [
            'access_token' => 'tok', 'refresh_token' => 'ref', 'ctid_trader_account_id' => 123,
        ]);
        $this->stubOpenSnapshotDefaults($this->ctraderConnector);
        $this->connectionRepo->method('findById')->willReturn($connection);
        $this->syncLogRepo->method('create')->willReturn(['id' => 1]);

        $this->credentialRepo->method('secondsSinceUpdate')->willReturn(4000);

        $this->ctraderConnector->expects($this->once())
            ->method('refreshCredentials')
            ->willReturnArgument(0);
        $this->ctraderConnector->method('fetchDeals')
            ->willReturn(['deals' => [], 'cursor' => null, 'raw_count' => 0]);
        $this->importService->method('importNormalizedPositions')->willReturn([
            'batch_id' => 1, 'imported_positions' => 0, 'imported_trades' => 0,
            'skipped_duplicates' => 0, 'skipped_errors' => 0, 'errors' => [],
        ]);

        $this->service->sync(1, 10);
    }

    public function testSyncRefreshesWhenNothingIsSharedAtAll(): void
    {
        // BingX and Ouinex store no shared row, so there is never anything to
        // be fresh — the refresh path must behave exactly as it always did.
        $connection = $this->makeConnection('BINGX', ['api_key' => 'k', 'api_secret' => 's']);
        $this->stubOpenSnapshotDefaults($this->bingxConnector);
        $this->connectionRepo->method('findById')->willReturn($connection);
        $this->syncLogRepo->method('create')->willReturn(['id' => 1]);

        $this->credentialRepo->method('secondsSinceUpdate')->willReturn(null);

        $this->bingxConnector->expects($this->once())
            ->method('refreshCredentials')
            ->willReturnArgument(0);
        $this->bingxConnector->method('fetchDeals')
            ->willReturn(['deals' => [], 'cursor' => null, 'raw_count' => 0]);
        $this->importService->method('importNormalizedPositions')->willReturn([
            'batch_id' => 1, 'imported_positions' => 0, 'imported_trades' => 0,
            'skipped_duplicates' => 0, 'skipped_errors' => 0, 'errors' => [],
        ]);

        $this->service->sync(1, 10);
    }

    public function testSyncPassesCursorForIncrementalSync(): void
    {
        $connection = $this->makeConnection();
        $connection['sync_cursor'] = '2024-11-20T00:00:00Z';
        $this->stubOpenSnapshotDefaults($this->metaApiConnector);

        $this->connectionRepo->method('findById')->willReturn($connection);
        $this->syncLogRepo->method('create')->willReturn(['id' => 1]);

        $this->metaApiConnector->method('refreshCredentials')->willReturnArgument(0);
        $this->metaApiConnector->expects($this->once())
            ->method('fetchDeals')
            ->with($this->anything(), '2024-11-20T00:00:00Z')
            ->willReturn(['deals' => [], 'cursor' => null, 'raw_count' => 0]);

        $this->importService->method('importNormalizedPositions')
            ->willReturn([
                'batch_id' => 1, 'imported_positions' => 0, 'imported_trades' => 0,
                'skipped_duplicates' => 0, 'skipped_errors' => 0, 'errors' => [],
            ]);

        $result = $this->service->sync(1, 10);
        $this->assertSame(0, $result['imported_positions']);
    }

    public function testSyncUsesCtraderConnectorForCtraderProvider(): void
    {
        $connection = $this->makeConnection('CTRADER', [
            'access_token' => 'tok', 'refresh_token' => 'ref', 'ctid_trader_account_id' => 123,
        ]);
        $this->stubOpenSnapshotDefaults($this->ctraderConnector);

        $this->connectionRepo->method('findById')->willReturn($connection);
        $this->syncLogRepo->method('create')->willReturn(['id' => 1]);

        $this->ctraderConnector->method('refreshCredentials')->willReturnArgument(0);
        $this->ctraderConnector->expects($this->once())
            ->method('fetchDeals')
            ->willReturn(['deals' => [], 'cursor' => null, 'raw_count' => 0]);

        $this->importService->method('importNormalizedPositions')
            ->willReturn([
                'batch_id' => 1, 'imported_positions' => 0, 'imported_trades' => 0,
                'skipped_duplicates' => 0, 'skipped_errors' => 0, 'errors' => [],
            ]);

        $result = $this->service->sync(1, 10);
        $this->assertSame(SyncStatus::SUCCESS->value, $result['status']);
    }

    public function testSyncUsesBingxConnectorForBingxProvider(): void
    {
        $connection = $this->makeConnection('BINGX', [
            'api_key' => 'k', 'api_secret' => 's',
        ]);
        $this->stubOpenSnapshotDefaults($this->bingxConnector);

        $this->connectionRepo->method('findById')->willReturn($connection);
        $this->syncLogRepo->method('create')->willReturn(['id' => 1]);

        $this->bingxConnector->method('refreshCredentials')->willReturnArgument(0);
        $this->bingxConnector->expects($this->once())
            ->method('fetchDeals')
            ->willReturn(['deals' => [], 'cursor' => null, 'raw_count' => 0]);

        $this->importService->method('importNormalizedPositions')
            ->willReturn([
                'batch_id' => 1, 'imported_positions' => 0, 'imported_trades' => 0,
                'skipped_duplicates' => 0, 'skipped_errors' => 0, 'errors' => [],
            ]);

        $result = $this->service->sync(1, 10);
        $this->assertSame(SyncStatus::SUCCESS->value, $result['status']);
    }

    public function testSyncUsesOuinexConnectorForOuinexProvider(): void
    {
        $connection = $this->makeConnection('OUINEX', [
            'service_api_key' => 'k', 'service_api_secret' => 's',
        ]);
        $this->stubOpenSnapshotDefaults($this->ouinexConnector);

        $this->connectionRepo->method('findById')->willReturn($connection);
        $this->syncLogRepo->method('create')->willReturn(['id' => 1]);

        // Connector returns refreshed creds (signin happened) — service must
        // re-encrypt and persist them.
        $this->ouinexConnector->method('refreshCredentials')->willReturn([
            'service_api_key' => 'k', 'service_api_secret' => 's',
            'jwt' => 'fresh', 'jwt_expires_at' => time() + 3600,
        ]);
        $this->ouinexConnector->expects($this->once())
            ->method('fetchDeals')
            ->willReturn(['deals' => [], 'cursor' => null, 'raw_count' => 0]);

        $this->importService->method('importNormalizedPositions')
            ->willReturn([
                'batch_id' => 1, 'imported_positions' => 0, 'imported_trades' => 0,
                'skipped_duplicates' => 0, 'skipped_errors' => 0, 'errors' => [],
            ]);

        $this->connectionRepo->expects($this->atLeastOnce())->method('update');

        $result = $this->service->sync(1, 10);
        $this->assertSame(SyncStatus::SUCCESS->value, $result['status']);
    }

    public function testSyncCallsOpenSnapshotDiffAfterClosedImport(): void
    {
        // The Ouinex provider must call fetchOpenPositions() AND hand the
        // result + the closed deals to BrokerOpenSyncService — that's how
        // OPEN positions get into the journal, and how OPEN→CLOSED
        // transitions are detected (the closed snapshot is the "decided"
        // set, the open snapshot is "still live").
        $connection = $this->makeConnection('OUINEX', [
            'service_api_key' => 'k', 'service_api_secret' => 's',
            'jwt' => 'cached', 'jwt_expires_at' => time() + 3600,
        ]);
        $this->connectionRepo->method('findById')->willReturn($connection);
        $this->syncLogRepo->method('create')->willReturn(['id' => 1]);

        $closedDeals = [
            [
                'symbol' => 'BTCUSDT', 'direction' => 'BUY',
                'entry_price' => 60000, 'exit_price' => 61500, 'size' => 0.5,
                'pnl' => 750.0, 'opened_at' => '2026-05-07 08:00:00',
                'closed_at' => '2026-05-07 14:30:00',
                'external_id' => 'ouinex_mp-now-closed',
                'pips' => null, 'comment' => null,
            ],
        ];
        $openPositions = [
            [
                'symbol' => 'ETHUSDT', 'direction' => 'SELL',
                'entry_price' => 4000, 'size' => 1.0, 'sl_price' => 4200,
                'opened_at' => '2026-05-07 09:00:00',
                'external_id' => 'ouinex_mp-live-2',
                'pnl' => null, 'comment' => null,
            ],
        ];

        $this->ouinexConnector->method('refreshCredentials')->willReturnArgument(0);
        $this->ouinexConnector->expects($this->once())
            ->method('fetchDeals')
            ->willReturn(['deals' => $closedDeals, 'cursor' => '2026-05-07T14:30:00Z', 'raw_count' => 1]);
        $this->ouinexConnector->expects($this->once())
            ->method('fetchOpenPositions')
            ->willReturn(['positions' => $openPositions, 'raw_count' => 1]);
        // Orders path is out of scope for this test but still called by the
        // service in every run. Stub it empty so it doesn't pollute the
        // assertions that target openSyncService.
        $this->ouinexConnector->method('fetchOpenOrders')
            ->willReturn(['orders' => [], 'raw_count' => 0]);
        $this->ouinexConnector->method('fetchClosedOrders')
            ->willReturn(['orders' => [], 'raw_count' => 0]);
        $this->orderSyncService->method('apply')
            ->willReturn(['inserted' => 0, 'updated' => 0, 'executed' => 0, 'expired' => 0, 'cancelled' => 0]);

        $this->importService->method('importNormalizedPositions')
            ->willReturn([
                'batch_id' => 42, 'imported_positions' => 1, 'imported_trades' => 1,
                'skipped_duplicates' => 0, 'skipped_errors' => 0, 'errors' => [],
            ]);

        // The diff service is called with: same user/account, the batch_id
        // returned by importNormalizedPositions, the open positions snapshot,
        // and the CLOSED snapshot too (so OPEN→CLOSED transitions can match
        // by external_id).
        $this->openSyncService->expects($this->once())
            ->method('apply')
            ->with(
                \App\Enums\BrokerProvider::OUINEX,
                10,        // userId
                5,         // accountId
                42,        // batchId from the closed import
                $openPositions,
                $closedDeals,
            )
            ->willReturn(['inserted' => 1, 'updated' => 0, 'transitioned' => 0, 'skipped_orphans' => 0]);

        $result = $this->service->sync(1, 10);

        $this->assertSame(SyncStatus::SUCCESS->value, $result['status']);
        // Stats from diff service are surfaced in the sync result so the UI
        // can show "X new live positions" without a separate API call.
        $this->assertSame(1, $result['live_inserted']);
        $this->assertSame(0, $result['live_transitioned']);
    }

    public function testSyncSkipsOpenSnapshotWhenConnectorReturnsEmpty(): void
    {
        // cTrader and MetaApi return ['positions' => [], ...] / ['orders' => [], ...]
        // by default — the diff services should still be called (so a
        // silent connector doesn't bypass reconciliation), but with empty
        // input both services are no-ops.
        $connection = $this->makeConnection('METAAPI');
        $this->connectionRepo->method('findById')->willReturn($connection);
        $this->syncLogRepo->method('create')->willReturn(['id' => 1]);

        $this->metaApiConnector->method('refreshCredentials')->willReturnArgument(0);
        $this->metaApiConnector->method('fetchDeals')
            ->willReturn(['deals' => [], 'cursor' => null, 'raw_count' => 0]);
        $this->metaApiConnector->expects($this->once())
            ->method('fetchOpenPositions')
            ->willReturn(['positions' => [], 'raw_count' => 0]);
        $this->metaApiConnector->expects($this->once())
            ->method('fetchOpenOrders')
            ->willReturn(['orders' => [], 'raw_count' => 0]);
        $this->metaApiConnector->expects($this->once())
            ->method('fetchClosedOrders')
            ->willReturn(['orders' => [], 'raw_count' => 0]);

        $this->importService->method('importNormalizedPositions')
            ->willReturn(['batch_id' => 7, 'imported_positions' => 0, 'imported_trades' => 0,
                'skipped_duplicates' => 0, 'skipped_errors' => 0, 'errors' => []]);

        $this->openSyncService->expects($this->once())
            ->method('apply')
            ->with(\App\Enums\BrokerProvider::METAAPI, 10, 5, 7, [], [])
            ->willReturn(['inserted' => 0, 'updated' => 0, 'transitioned' => 0, 'skipped_orphans' => 0]);
        $this->orderSyncService->expects($this->once())
            ->method('apply')
            ->with(\App\Enums\BrokerProvider::METAAPI, 10, 5, 7, [], [])
            ->willReturn(['inserted' => 0, 'updated' => 0, 'executed' => 0, 'expired' => 0, 'cancelled' => 0]);

        $result = $this->service->sync(1, 10);
        $this->assertSame(SyncStatus::SUCCESS->value, $result['status']);
    }

    public function testSyncCallsOrderSnapshotDiffAfterOpenPositionsDiff(): void
    {
        // The Ouinex sync flow runs: closed deals → open positions diff →
        // open orders + closed orders diff. The order diff receives both
        // the open and closed snapshots so it can disambiguate EXECUTED
        // from CANCELLED on disappearance.
        $connection = $this->makeConnection('OUINEX', [
            'service_api_key' => 'k', 'service_api_secret' => 's',
            'jwt' => 'cached', 'jwt_expires_at' => time() + 3600,
        ]);
        $this->connectionRepo->method('findById')->willReturn($connection);
        $this->syncLogRepo->method('create')->willReturn(['id' => 1]);

        $openOrders = [
            [
                'symbol' => 'BTCUSDT', 'direction' => 'BUY',
                'entry_price' => 58000, 'size' => 0.5, 'sl_price' => 57000,
                'tp_price' => 62000, 'expires_at' => '2026-06-01 00:00:00',
                'created_at' => '2026-05-07 08:00:00',
                'external_id' => 'ouinex_order_ord-42',
            ],
        ];
        $closedOrders = [
            ['external_id' => 'ouinex_order_ord-99', 'final_status' => 'EXECUTED'],
        ];

        $this->ouinexConnector->method('refreshCredentials')->willReturnArgument(0);
        $this->ouinexConnector->method('fetchDeals')
            ->willReturn(['deals' => [], 'cursor' => null, 'raw_count' => 0]);
        $this->ouinexConnector->method('fetchOpenPositions')
            ->willReturn(['positions' => [], 'raw_count' => 0]);
        $this->ouinexConnector->expects($this->once())
            ->method('fetchOpenOrders')
            ->willReturn(['orders' => $openOrders, 'raw_count' => 1]);
        $this->ouinexConnector->expects($this->once())
            ->method('fetchClosedOrders')
            ->willReturn(['orders' => $closedOrders, 'raw_count' => 1]);

        $this->importService->method('importNormalizedPositions')
            ->willReturn([
                'batch_id' => 42, 'imported_positions' => 0, 'imported_trades' => 0,
                'skipped_duplicates' => 0, 'skipped_errors' => 0, 'errors' => [],
            ]);
        $this->openSyncService->method('apply')
            ->willReturn(['inserted' => 0, 'updated' => 0, 'transitioned' => 0, 'skipped_orphans' => 0]);

        $this->orderSyncService->expects($this->once())
            ->method('apply')
            ->with(\App\Enums\BrokerProvider::OUINEX, 10, 5, 42, $openOrders, $closedOrders)
            ->willReturn(['inserted' => 1, 'updated' => 0, 'executed' => 0, 'expired' => 0, 'cancelled' => 0]);

        $result = $this->service->sync(1, 10);

        $this->assertSame(SyncStatus::SUCCESS->value, $result['status']);
        $this->assertSame(1, $result['pending_inserted']);
        $this->assertSame(0, $result['pending_cancelled']);
        $this->assertSame(0, $result['pending_executed']);
        $this->assertSame(0, $result['pending_expired']);
    }

    // ── Timezone handed to the connector ────────────────────────────

    public function testSyncHandsTheUsersTimezoneToTheConnector(): void
    {
        // The journal's DATETIME columns hold local wall-clock time, so the
        // connector has to know which clock to render broker instants on.
        // Without it a trade opened at 07:29 in Paris is journalled as 05:29,
        // two hours away from every hand-entered trade beside it.
        $userRepo = $this->createMock(\App\Repositories\UserRepository::class);
        $userRepo->method('findById')->willReturn(['id' => 10, 'timezone' => 'Europe/Paris']);

        $spy = new TimezoneSpyConnector([]);
        $this->primeSyncStubs();

        $this->makeServiceWith($spy, $userRepo)->sync(1, 10);

        $this->assertSame('Europe/Paris', $spy->spiedTimezone);
    }

    public function testSyncLeavesTheConnectorOnUtcWhenTheTimezoneIsUnknown(): void
    {
        // No repository injected → null, i.e. the UTC behaviour that predates
        // this. A sync must never fail because a timezone could not be read.
        $spy = new TimezoneSpyConnector([]);
        $this->primeSyncStubs();

        $this->makeServiceWith($spy, null)->sync(1, 10);

        $this->assertNull($spy->spiedTimezone);
    }

    // ── Reservation (one sync at a time per connection) ──────────────

    public function testSyncReservesTheConnectionBeforeTouchingTheBroker(): void
    {
        // Nothing serialises the manual sync against the scheduled one, so two
        // runs can otherwise import the same deals concurrently.
        $repo = $this->createMock(BrokerConnectionRepository::class);
        $repo->method('findById')->willReturn($this->makeConnection('CTRADER'));
        $repo->expects($this->once())
            ->method('claimForSync')
            ->with(1, BrokerSyncService::SYNC_CLAIM_TTL_SECONDS)
            ->willReturn(true);

        $this->primeSyncStubsOn($repo);

        $this->makeServiceWithRepo($repo)->sync(1, 10);
    }

    public function testSyncSkipsWithoutWorkingWhenTheConnectionIsAlreadySyncing(): void
    {
        $repo = $this->createMock(BrokerConnectionRepository::class);
        $repo->method('findById')->willReturn($this->makeConnection('CTRADER'));
        $repo->method('claimForSync')->willReturn(false);

        // A refused reservation is not a failure: nothing is logged, nothing is
        // fetched, and the connection state is left exactly as the holder found it.
        $repo->expects($this->never())->method('update');
        $repo->expects($this->never())->method('releaseSync');
        $this->syncLogRepo->expects($this->never())->method('create');

        $spy = new TimezoneSpyConnector([]);
        $result = $this->makeServiceWithRepo($repo, $spy)->sync(1, 10);

        $this->assertSame(SyncStatus::SKIPPED->value, $result['status']);
        $this->assertSame(0, $result['imported_positions']);
    }

    public function testSyncReleasesTheReservationOnSuccess(): void
    {
        $repo = $this->createMock(BrokerConnectionRepository::class);
        $repo->method('findById')->willReturn($this->makeConnection('CTRADER'));
        $repo->method('claimForSync')->willReturn(true);
        $repo->expects($this->once())->method('releaseSync')->with(1);

        $this->primeSyncStubsOn($repo);

        $this->makeServiceWithRepo($repo)->sync(1, 10);
    }

    public function testSyncReleasesTheReservationWhenTheSyncBlowsUp(): void
    {
        // Without a finally, one crash leaves the connection locked until the
        // staleness window expires — 15 minutes of no sync for that account.
        $repo = $this->createMock(BrokerConnectionRepository::class);
        $repo->method('findById')->willReturn($this->makeConnection('CTRADER'));
        $repo->method('claimForSync')->willReturn(true);
        $repo->expects($this->once())->method('releaseSync')->with(1);

        $this->syncLogRepo->method('create')->willReturn(['id' => 1]);
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->method('refreshCredentials')->willReturnArgument(0);
        $connector->method('fetchDeals')->willThrowException(new \RuntimeException('broker down'));

        $this->expectException(\RuntimeException::class);

        $this->makeServiceWithRepo($repo, $connector)->sync(1, 10);
    }

    // ── requestSync (the button, non-blocking) ───────────────────────

    public function testRequestSyncFlagsTheConnectionWithoutTouchingTheBroker(): void
    {
        // A cTrader sync opens five WebSocket sessions in a row. Doing that
        // inside the HTTP request means the user waits, and a proxy timeout can
        // cut it in half.
        $repo = $this->createMock(BrokerConnectionRepository::class);
        $repo->method('findById')->willReturn($this->makeConnection('CTRADER'));
        $repo->expects($this->once())->method('requestSync')->with(1);
        $repo->expects($this->never())->method('claimForSync');
        $this->syncLogRepo->expects($this->never())->method('create');

        $result = $this->makeServiceWithRepo($repo)->requestSync(1, 10);

        $this->assertSame(SyncStatus::QUEUED->value, $result['status']);
    }

    public function testRequestSyncReportsAnAlreadyRunningSync(): void
    {
        $connection = $this->makeConnection('CTRADER');
        $connection['syncing_since'] = '2026-08-07 09:00:00';
        $repo = $this->createMock(BrokerConnectionRepository::class);
        $repo->method('findById')->willReturn($connection);

        // Still queued: the user wants a fresh pass, and the running one already
        // took its reservation so it will not swallow this request.
        $repo->expects($this->once())->method('requestSync')->with(1);

        $result = $this->makeServiceWithRepo($repo)->requestSync(1, 10);

        $this->assertSame(SyncStatus::QUEUED->value, $result['status']);
        $this->assertTrue($result['syncing']);
    }

    public function testRequestSyncRejectsAnotherUsersConnection(): void
    {
        $repo = $this->createMock(BrokerConnectionRepository::class);
        $repo->method('findById')->willReturn($this->makeConnection('CTRADER'));
        $repo->expects($this->never())->method('requestSync');

        $this->expectException(\App\Exceptions\ForbiddenException::class);

        $this->makeServiceWithRepo($repo)->requestSync(1, 999);
    }

    public function testRequestSyncRejectsANonActiveConnection(): void
    {
        $connection = $this->makeConnection('CTRADER');
        $connection['status'] = ConnectionStatus::ERROR->value;
        $repo = $this->createMock(BrokerConnectionRepository::class);
        $repo->method('findById')->willReturn($connection);
        $repo->expects($this->never())->method('requestSync');

        $this->expectException(\App\Exceptions\ValidationException::class);

        $this->makeServiceWithRepo($repo)->requestSync(1, 10);
    }

    /** primeSyncStubs against a bespoke repository mock. */
    private function primeSyncStubsOn(BrokerConnectionRepository $repo): void
    {
        $this->syncLogRepo->method('create')->willReturn(['id' => 1]);
        $this->importService->method('importNormalizedPositions')->willReturn([
            'batch_id' => 1, 'imported_positions' => 0, 'imported_trades' => 0,
            'skipped_duplicates' => 0, 'skipped_errors' => 0, 'errors' => [],
        ]);
        $this->openSyncService->method('apply')
            ->willReturn(['inserted' => 0, 'updated' => 0, 'transitioned' => 0, 'skipped_orphans' => 0]);
        $this->orderSyncService->method('apply')
            ->willReturn(['inserted' => 0, 'updated' => 0, 'executed' => 0, 'expired' => 0, 'cancelled' => 0]);
    }

    private function makeServiceWithRepo(
        BrokerConnectionRepository $repo,
        ?ConnectorInterface $ctrader = null,
    ): BrokerSyncService {
        return new BrokerSyncService(
            $repo,
            $this->syncLogRepo,
            $this->importService,
            new RowGroupingService(),
            $this->credentialStore,
            $ctrader ?? new TimezoneSpyConnector([]),
            $this->metaApiConnector,
            $this->ouinexConnector,
            $this->bingxConnector,
            $this->openSyncService,
            $this->orderSyncService,
        );
    }

    /** The collaborators a sync touches beyond the connector under test. */
    private function primeSyncStubs(): void
    {
        $this->connectionRepo->method('findById')->willReturn($this->makeConnection('CTRADER'));
        $this->syncLogRepo->method('create')->willReturn(['id' => 1]);
        $this->importService->method('importNormalizedPositions')->willReturn([
            'batch_id' => 1, 'imported_positions' => 0, 'imported_trades' => 0,
            'skipped_duplicates' => 0, 'skipped_errors' => 0, 'errors' => [],
        ]);
        $this->openSyncService->method('apply')
            ->willReturn(['inserted' => 0, 'updated' => 0, 'transitioned' => 0, 'skipped_orphans' => 0]);
        $this->orderSyncService->method('apply')
            ->willReturn(['inserted' => 0, 'updated' => 0, 'executed' => 0, 'expired' => 0, 'cancelled' => 0]);
    }

    public function testARunJournalisesWhatItSpentAtTheBroker(): void
    {
        // FTMO disables a trading account past 2 000 server requests a day, and
        // working out our own figure meant reading the connector line by line.
        // The run has to say what it cost, against the connection it cost it
        // on — a total with no connection_id cannot be traced back to a culprit.
        $this->primeSyncStubs();
        $service = $this->makeServiceWithRepo($this->connectionRepo, new RequestBudgetSpyConnector([]));

        $lines = $this->captureErrorLog(fn() => $service->sync(1, 10));

        $budget = null;
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (($entry['event'] ?? null) === 'sync_request_budget') {
                $budget = $entry;
            }
        }

        $this->assertNotNull($budget, 'the run never said what it spent');
        $this->assertSame('ctrader', $budget['job']);
        $this->assertSame(1, $budget['connection_id']);
        $this->assertSame(9, $budget['requests']);
        $this->assertSame(1, $budget['by_type']['ProtoOAReconcileReq']);
    }

    public function testAConnectorThatCountsNothingIsNotJournalised(): void
    {
        // Only cTrader counts today. A line reading "0 requests" for MetaApi
        // would be read as "this sync was free", which is the opposite of true.
        $this->primeSyncStubs();
        $service = $this->makeServiceWithRepo($this->connectionRepo, new TimezoneSpyConnector([]));

        $lines = $this->captureErrorLog(fn() => $service->sync(1, 10));

        $budgetLines = array_filter(
            $lines,
            fn($l) => (json_decode($l, true)['event'] ?? null) === 'sync_request_budget',
        );
        $this->assertSame([], array_values($budgetLines));
    }

    /**
     * BrokerLogger writes to error_log(), the portable stderr-ish sink. Point
     * it at a file for the duration of the call and read back what landed.
     *
     * Writing to a FILE makes error_log() prepend "[date UTC] " to every line —
     * a prefix that does not exist when the same call goes to stderr, which is
     * where it goes in production. Strip it, or every line comes back as
     * unparseable JSON and the assertions read as "nothing was logged".
     *
     * @return list<string>
     */
    private function captureErrorLog(callable $run): array
    {
        $file = tempnam(sys_get_temp_dir(), 'brokerlog');
        $previous = ini_get('error_log');
        ini_set('error_log', $file);

        try {
            $run();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $contents = file_get_contents($file) ?: '';
        unlink($file);

        return array_values(array_map(
            fn($l) => preg_replace('/^\[[^\]]+\]\s*/', '', $l),
            array_filter(explode("\n", $contents), fn($l) => trim($l) !== ''),
        ));
    }

    private function makeServiceWith(
        ConnectorInterface $ctrader,
        ?\App\Repositories\UserRepository $userRepo,
    ): BrokerSyncService {
        return new BrokerSyncService(
            $this->connectionRepo,
            $this->syncLogRepo,
            $this->importService,
            new RowGroupingService(),
            $this->credentialStore,
            $ctrader,
            $this->metaApiConnector,
            $this->ouinexConnector,
            $this->bingxConnector,
            $this->openSyncService,
            $this->orderSyncService,
            null,
            $userRepo,
        );
    }
}

/**
 * A real connector — so it carries the NormalizesInUserTimezone trait — with
 * its network calls stubbed out, recording the timezone the sync service hands
 * it. A plain interface mock would not do: the point is that the wiring reaches
 * a connector that actually implements setTimezone().
 */
class TimezoneSpyConnector extends \App\Services\Broker\CtraderConnector
{
    public ?string $spiedTimezone = null;

    public function setTimezone(?string $timezone): void
    {
        $this->spiedTimezone = $timezone;
        parent::setTimezone($timezone);
    }

    public function fetchDeals(array $credentials, ?string $sinceCursor = null): array
    {
        return ['deals' => [], 'cursor' => null, 'raw_count' => 0];
    }

    public function fetchOpenPositions(array $credentials): array
    {
        return ['positions' => [], 'raw_count' => 0];
    }

    public function fetchOpenOrders(array $credentials): array
    {
        return ['orders' => [], 'raw_count' => 0];
    }

    public function fetchClosedOrders(array $credentials, ?string $sinceCursor = null): array
    {
        return ['orders' => [], 'raw_count' => 0];
    }

    public function fetchBalance(array $credentials): ?float
    {
        return null;
    }

    public function refreshCredentials(array $credentials): array
    {
        return $credentials;
    }
}

/**
 * A connector that reports a request bill. The counting itself is CtraderConnector's
 * job and is tested there; what this exercises is the wiring — that the sync
 * service asks, and journalises the answer against the right connection.
 */
class RequestBudgetSpyConnector extends TimezoneSpyConnector
{
    public function getRequestCounts(): array
    {
        return [
            'total' => 9,
            'by_type' => [
                'ProtoOAApplicationAuthReq' => 1,
                'ProtoOAAccountAuthReq' => 1,
                'ProtoOAReconcileReq' => 1,
                'ProtoOADealListReq' => 1,
                'ProtoOASymbolsListReq' => 1,
                'ProtoOASymbolByIdReq' => 1,
                'ProtoOAOrderListReq' => 1,
                'ProtoOATraderReq' => 1,
                'ProtoOAAssetListReq' => 1,
            ],
        ];
    }
}
