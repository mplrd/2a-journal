<?php

namespace Tests\Unit\Services\Broker;

use App\Enums\BrokerProvider;
use App\Enums\ConnectionStatus;
use App\Repositories\BrokerConnectionRepository;
use App\Services\Broker\BrokerSyncSchedulerService;
use App\Services\Broker\BrokerSyncService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BrokerSyncSchedulerServiceTest extends TestCase
{
    private BrokerConnectionRepository $connectionRepo;
    private BrokerSyncService $syncService;

    protected function setUp(): void
    {
        $this->connectionRepo = $this->createMock(BrokerConnectionRepository::class);
        $this->syncService = $this->createMock(BrokerSyncService::class);
    }

    private function makeScheduler(array $configOverrides = []): BrokerSyncSchedulerService
    {
        $config = array_merge([
            'auto_sync_enabled' => true,
            'sync_interval_minutes' => 15,
            'max_consecutive_failures' => 3,
        ], $configOverrides);

        return new BrokerSyncSchedulerService(
            $this->connectionRepo,
            $this->syncService,
            $config
        );
    }

    private function connectionRow(int $id, int $userId, int $consecutiveFailures = 0): array
    {
        return [
            'id' => $id,
            'user_id' => $userId,
            'account_id' => 1000 + $id,
            'provider' => BrokerProvider::METAAPI->value,
            'status' => ConnectionStatus::ACTIVE->value,
            'consecutive_failures' => $consecutiveFailures,
            'last_sync_at' => null,
        ];
    }

    // ── No-op when disabled ─────────────────────────────────────

    public function testReturnsSkippedWhenAutoSyncDisabled(): void
    {
        $scheduler = $this->makeScheduler(['auto_sync_enabled' => false]);

        $this->connectionRepo->expects($this->never())->method('findDueForAutoSync');
        $this->syncService->expects($this->never())->method('sync');

        $result = $scheduler->runDueConnections();

        $this->assertTrue($result['skipped']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['success']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['deactivated']);
    }

    // ── Happy path: all succeed ─────────────────────────────────

    public function testAllConnectionsSucceedResetsFailures(): void
    {
        $scheduler = $this->makeScheduler();

        $conn1 = $this->connectionRow(1, 10);
        $conn2 = $this->connectionRow(2, 20);

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([$conn1, $conn2]);

        $this->syncService->expects($this->exactly(2))->method('sync');

        $this->connectionRepo->expects($this->exactly(2))->method('resetFailures');
        $this->connectionRepo->expects($this->never())->method('incrementFailures');
        $this->connectionRepo->expects($this->never())->method('markError');

        $result = $scheduler->runDueConnections();

        $this->assertFalse($result['skipped']);
        $this->assertSame(2, $result['processed']);
        $this->assertSame(2, $result['success']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['deactivated']);
    }

    // ── Mix: 1 ok + 1 non-fatal fail ────────────────────────────

    public function testMixedResultIncrementsOnlyFailedAndKeepsOthersSynced(): void
    {
        $scheduler = $this->makeScheduler();

        $connOk = $this->connectionRow(1, 10, 0);
        $connKo = $this->connectionRow(2, 20, 0); // first failure

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([$connOk, $connKo]);

        $this->syncService->method('sync')->willReturnCallback(function (int $id) {
            if ($id === 2) {
                throw new RuntimeException('broker timeout');
            }
            return ['status' => 'SUCCESS'];
        });

        $this->connectionRepo->expects($this->once())->method('resetFailures')->with(1);
        $this->connectionRepo->expects($this->once())->method('incrementFailures')->with(2);
        $this->connectionRepo->expects($this->never())->method('markError');

        $result = $scheduler->runDueConnections();

        $this->assertSame(2, $result['processed']);
        $this->assertSame(1, $result['success']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['deactivated']);
    }

    // ── Circuit breaker: 3rd consecutive failure deactivates ───

    public function testReachingMaxConsecutiveFailuresMarksError(): void
    {
        $scheduler = $this->makeScheduler();

        // Connection already has 2 failures, this call will be the 3rd
        $connKo = $this->connectionRow(7, 10, 2);

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([$connKo]);

        $this->syncService->method('sync')->willThrowException(new RuntimeException('oauth expired'));

        $this->connectionRepo->expects($this->once())->method('incrementFailures')->with(7);
        $this->connectionRepo->expects($this->once())
            ->method('markError')
            ->with(7, $this->stringContains('oauth expired'));

        $result = $scheduler->runDueConnections();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['success']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['deactivated']);
    }

    // ── Empty: nothing due ──────────────────────────────────────

    public function testNothingDueReturnsZeros(): void
    {
        $scheduler = $this->makeScheduler();

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([]);
        $this->syncService->expects($this->never())->method('sync');

        $result = $scheduler->runDueConnections();

        $this->assertFalse($result['skipped']);
        $this->assertSame(0, $result['processed']);
    }
}
