<?php

namespace Tests\Unit\Services\Broker;

use App\Enums\BrokerProvider;
use App\Enums\ConnectionStatus;
use App\Enums\SyncStatus;
use App\Repositories\BrokerConnectionRepository;
use App\Repositories\SyncLogRepository;
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

    protected function setUp(): void
    {
        $this->connectionRepo = $this->createMock(BrokerConnectionRepository::class);
        $this->syncLogRepo = $this->createMock(SyncLogRepository::class);
        $this->importService = $this->createMock(ImportService::class);
        $this->crypto = new CredentialEncryptionService(random_bytes(32));
        $this->metaApiConnector = $this->createMock(ConnectorInterface::class);
        $this->ctraderConnector = $this->createMock(ConnectorInterface::class);

        $this->service = new BrokerSyncService(
            $this->connectionRepo,
            $this->syncLogRepo,
            $this->importService,
            new RowGroupingService(),
            $this->crypto,
            $this->ctraderConnector,
            $this->metaApiConnector,
        );
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
}
