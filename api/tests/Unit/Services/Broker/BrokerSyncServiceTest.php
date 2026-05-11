<?php

namespace Tests\Unit\Services\Broker;

use App\Enums\BrokerProvider;
use App\Enums\ConnectionStatus;
use App\Enums\SyncStatus;
use App\Repositories\BrokerConnectionRepository;
use App\Repositories\SyncLogRepository;
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
        $this->metaApiConnector = $this->createMock(ConnectorInterface::class);
        $this->ctraderConnector = $this->createMock(ConnectorInterface::class);
        $this->ouinexConnector = $this->createMock(ConnectorInterface::class);
        $this->bingxConnector = $this->createMock(ConnectorInterface::class);
        $this->openSyncService = $this->createMock(BrokerOpenSyncService::class);
        $this->orderSyncService = $this->createMock(BrokerOrderSyncService::class);

        $this->service = new BrokerSyncService(
            $this->connectionRepo,
            $this->syncLogRepo,
            $this->importService,
            new RowGroupingService(),
            $this->crypto,
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
}
