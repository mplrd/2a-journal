<?php

namespace Tests\Integration\Robots;

use App\Core\Database;
use App\Enums\RobotStatus;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\RobotRepository;
use App\Repositories\TradingViewAlertEventRepository;
use App\Repositories\TradingViewWebhookRepository;
use App\Services\RobotService;
use PDO;
use PHPUnit\Framework\TestCase;

class RobotServiceTest extends TestCase
{
    private PDO $pdo;
    private RobotService $service;
    private RobotRepository $robotRepo;
    private TradingViewWebhookRepository $webhookRepo;
    private int $userId;
    private int $otherUserId;
    private int $accountId;

    protected function setUp(): void
    {
        $envFile = __DIR__ . '/../../../.env';
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                if (($eq = strpos($line, '=')) === false) continue;
                $k = trim(substr($line, 0, $eq));
                $v = trim(substr($line, $eq + 1));
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[0] === $v[strlen($v) - 1]) $v = substr($v, 1, -1);
                if (!getenv($k)) putenv("$k=$v");
            }
        }

        Database::reset();
        $this->pdo = Database::getConnection();
        $this->cleanup();

        $this->robotRepo = new RobotRepository($this->pdo);
        $this->webhookRepo = new TradingViewWebhookRepository($this->pdo);
        $accountRepo = new AccountRepository($this->pdo);

        $this->service = new RobotService(
            $this->robotRepo,
            $this->webhookRepo,
            new TradingViewAlertEventRepository($this->pdo),
            $accountRepo,
            'http://test.local/api/webhooks/tradingview',
        );

        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('robot-owner@test.com', 'h')");
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('robot-other@test.com', 'h')");
        $this->otherUserId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO accounts (user_id, name, account_type, initial_capital, currency)
             VALUES (:u, 'Robot Acct', 'BROKER_DEMO', 10000, 'USD')"
        )->execute(['u' => $this->userId]);
        $this->accountId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['tradingview_alert_events', 'tradingview_webhooks', 'robots', 'accounts'] as $t) {
            $this->pdo->exec("DELETE FROM {$t}");
        }
        $this->pdo->exec("DELETE FROM users WHERE email IN ('robot-owner@test.com','robot-other@test.com')");
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testCreateReturnsRobotPlusOneShotCredentials(): void
    {
        $result = $this->service->create($this->userId, ['name' => 'My TV bot', 'account_id' => $this->accountId]);

        $this->assertSame('My TV bot', $result['robot']['name']);
        $this->assertSame(RobotStatus::ACTIVE->value, $result['robot']['status']);
        $this->assertStringContainsString('/webhooks/tradingview/', $result['url']);
        $this->assertNotEmpty($result['body_secret']);
        // One template per action; OPEN carries the body secret + the action tag.
        $this->assertSame($result['body_secret'], $result['templates']['OPEN']['secret']);
        $this->assertSame('OPEN', $result['templates']['OPEN']['action']);
        $this->assertArrayHasKey('MODIFY', $result['templates']);
        $this->assertArrayHasKey('CLOSE', $result['templates']);
        $this->assertArrayHasKey('CANCEL', $result['templates']);
        $this->assertSame($result['templates']['OPEN']['client_order_id'], $result['templates']['MODIFY']['client_order_id']);

        // A webhook row was created for the robot, storing only hashes.
        $webhook = $this->webhookRepo->findByRobotId((int) $result['robot']['id']);
        $this->assertNotNull($webhook);
        $this->assertSame(hash('sha256', $result['body_secret']), $webhook['body_secret_hash']);
    }

    public function testCreateRejectsEmptyName(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->userId, ['name' => '  ', 'account_id' => $this->accountId]);
    }

    public function testCreateRejectsAccountOwnedByAnother(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->service->create($this->otherUserId, ['name' => 'x', 'account_id' => $this->accountId]);
    }

    public function testListReturnsOnlyOwnNonArchivedRobots(): void
    {
        $this->service->create($this->userId, ['name' => 'A', 'account_id' => $this->accountId]);
        $b = $this->service->create($this->userId, ['name' => 'B', 'account_id' => $this->accountId]);
        $this->service->archive($this->userId, (int) $b['robot']['id']);

        $list = $this->service->listForUser($this->userId);
        $this->assertCount(1, $list);
        $this->assertSame('A', $list[0]['name']);
        $this->assertArrayHasKey('account_name', $list[0]);
    }

    public function testChangeStatusPausesAndResumes(): void
    {
        $robot = $this->service->create($this->userId, ['name' => 'A', 'account_id' => $this->accountId])['robot'];

        $paused = $this->service->changeStatus($this->userId, (int) $robot['id'], 'PAUSED');
        $this->assertSame(RobotStatus::PAUSED->value, $paused['status']);

        $resumed = $this->service->changeStatus($this->userId, (int) $robot['id'], 'ACTIVE');
        $this->assertSame(RobotStatus::ACTIVE->value, $resumed['status']);
    }

    public function testChangeStatusRejectsInvalidValue(): void
    {
        $robot = $this->service->create($this->userId, ['name' => 'A', 'account_id' => $this->accountId])['robot'];
        $this->expectException(ValidationException::class);
        $this->service->changeStatus($this->userId, (int) $robot['id'], 'NONSENSE');
    }

    public function testArchivedRobotIsNotFoundAnymore(): void
    {
        $robot = $this->service->create($this->userId, ['name' => 'A', 'account_id' => $this->accountId])['robot'];
        $this->service->archive($this->userId, (int) $robot['id']);

        $this->expectException(NotFoundException::class);
        $this->service->getForUser($this->userId, (int) $robot['id']);
    }

    public function testCannotTouchAnotherUsersRobot(): void
    {
        $robot = $this->service->create($this->userId, ['name' => 'A', 'account_id' => $this->accountId])['robot'];

        $this->expectException(ForbiddenException::class);
        $this->service->changeStatus($this->otherUserId, (int) $robot['id'], 'PAUSED');
    }

    public function testEnforcesMaxRobotsPerAccount(): void
    {
        for ($i = 0; $i < RobotService::MAX_PER_ACCOUNT; $i++) {
            $this->service->create($this->userId, ['name' => "R{$i}", 'account_id' => $this->accountId]);
        }

        $this->expectException(ValidationException::class);
        $this->service->create($this->userId, ['name' => 'overflow', 'account_id' => $this->accountId]);
    }

    public function testGetDetailMasksUrlAndSecret(): void
    {
        $robot = $this->service->create($this->userId, ['name' => 'A', 'account_id' => $this->accountId])['robot'];

        $detail = $this->service->getDetailForUser($this->userId, (int) $robot['id']);

        $this->assertSame((int) $robot['id'], (int) $detail['robot']['id']);
        $this->assertTrue($detail['webhook']['exists']);
        $this->assertStringContainsString('•', $detail['webhook']['url_masked']);
        $this->assertStringContainsString('•', $detail['webhook']['secret_masked']);
        $this->assertStringContainsString('•', $detail['webhook']['templates']['OPEN']['secret']);
    }

    public function testRegenerateIssuesFreshCredentialsAndInvalidatesOld(): void
    {
        $created = $this->service->create($this->userId, ['name' => 'A', 'account_id' => $this->accountId]);
        $robotId = (int) $created['robot']['id'];
        $oldHash = $this->webhookRepo->findByRobotId($robotId)['url_token_hash'];

        $regen = $this->service->regenerate($this->userId, $robotId);

        // New one-shot credentials, different from the originals.
        $this->assertNotSame($created['url'], $regen['url']);
        $this->assertNotSame($created['body_secret'], $regen['body_secret']);
        $this->assertSame($regen['body_secret'], $regen['templates']['OPEN']['secret']);

        // Exactly one webhook remains; its token hash matches the new URL's token
        // and differs from the old one (old credentials invalidated).
        $current = $this->webhookRepo->findByRobotId($robotId);
        $this->assertNotNull($current);
        $this->assertNotSame($oldHash, $current['url_token_hash']);
        $parts = explode('/', $regen['url']);
        $this->assertSame(hash('sha256', end($parts)), $current['url_token_hash']);
    }

    public function testRegenerateRejectsAnotherUsersRobot(): void
    {
        $robot = $this->service->create($this->userId, ['name' => 'A', 'account_id' => $this->accountId])['robot'];

        $this->expectException(ForbiddenException::class);
        $this->service->regenerate($this->otherUserId, (int) $robot['id']);
    }
}
