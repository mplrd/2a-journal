<?php

namespace Tests\Integration\Stats;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class StatsFlowTest extends TestCase
{
    private Router $router;
    private PDO $pdo;
    private string $accessToken;
    private int $userId;
    private int $accountId;
    private int $accountId2;

    protected function setUp(): void
    {
        $envFile = __DIR__ . '/../../../.env';
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

        Database::reset();
        $this->pdo = Database::getConnection();

        // Clean tables
        $this->pdo->exec('DELETE FROM partial_exits');
        $this->pdo->exec('DELETE FROM status_history');
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM orders');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');

        // Build router
        $router = new Router();
        require __DIR__ . '/../../../config/routes.php';
        $this->router = $router;

        // Register a user and get access token
        $request = Request::create('POST', '/auth/register', [
            'email' => 'stats@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $data = $response->getBody()['data'];
        $this->accessToken = $data['access_token'];

        // Get user ID
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => 'stats@test.com']);
        $this->userId = (int) $stmt->fetchColumn();

        // Create two accounts
        $response = $this->router->dispatch($this->authRequest('POST', '/accounts', [
            'name' => 'Account 1',
            'account_type' => 'BROKER_DEMO',
        ]));
        $this->accountId = (int) $response->getBody()['data']['id'];

        $response = $this->router->dispatch($this->authRequest('POST', '/accounts', [
            'name' => 'Account 2',
            'account_type' => 'BROKER_LIVE',
        ]));
        $this->accountId2 = (int) $response->getBody()['data']['id'];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM partial_exits');
        $this->pdo->exec('DELETE FROM status_history');
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM orders');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    private function authRequest(string $method, string $uri, array $body = [], array $query = []): Request
    {
        return Request::create($method, $uri, $body, $query, [
            'Authorization' => "Bearer {$this->accessToken}",
        ]);
    }

    private function createAndCloseTrade(int $accountId, float $exitPrice, string $exitType = 'TP'): void
    {
        $this->createAndCloseTradeWithOptions($accountId, $exitPrice, $exitType);
    }

    private function createAndCloseTradeWithDate(int $accountId, float $exitPrice, string $exitType, string $closedAt): void
    {
        $this->createAndCloseTradeWithOptions($accountId, $exitPrice, $exitType, [
            'opened_at' => $closedAt,
        ]);
        // Update closed_at directly since the close API uses current time
        $stmt = $this->pdo->prepare('UPDATE trades SET closed_at = :closed_at ORDER BY id DESC LIMIT 1');
        $stmt->execute(['closed_at' => $closedAt]);
    }

    private function createAndCloseTradeWithOptions(int $accountId, float $exitPrice, string $exitType = 'TP', array $options = []): void
    {
        $response = $this->router->dispatch($this->authRequest('POST', '/trades', [
            'account_id' => $accountId,
            'direction' => $options['direction'] ?? 'BUY',
            'symbol' => $options['symbol'] ?? 'NASDAQ',
            'entry_price' => 18500,
            'size' => 1,
            'setup' => ['Breakout'],
            'sl_points' => 50,
            'opened_at' => $options['opened_at'] ?? '2026-01-15 10:00:00',
        ]));
        $tradeId = $response->getBody()['data']['id'];

        $this->router->dispatch($this->authRequest('POST', "/trades/{$tradeId}/close", [
            'exit_price' => $exitPrice,
            'exit_size' => 1,
            'exit_type' => $exitType,
        ]));
    }

    // ── Overview ────────────────────────────────────────────────

    public function testOverviewReturns200WithStructure(): void
    {
        $this->createAndCloseTrade($this->accountId, 18600, 'TP');
        $this->createAndCloseTrade($this->accountId, 18400, 'SL');

        $response = $this->router->dispatch($this->authRequest('GET', '/stats/overview'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('overview', $body['data']);
        $this->assertArrayHasKey('recent_trades', $body['data']);
        $this->assertSame(2, $body['data']['overview']['total_trades']);
        $this->assertArrayHasKey('win_rate', $body['data']['overview']);
        $this->assertArrayHasKey('profit_factor', $body['data']['overview']);
    }

    public function testOverviewRequiresAuth(): void
    {
        $request = Request::create('GET', '/stats/overview');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testOverviewFiltersByAccount(): void
    {
        $this->createAndCloseTrade($this->accountId, 18600, 'TP');
        $this->createAndCloseTrade($this->accountId2, 18400, 'SL');

        $response = $this->router->dispatch(
            $this->authRequest('GET', '/stats/overview', [], ['account_id' => $this->accountId])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $body['data']['overview']['total_trades']);
    }

    // ── Charts ──────────────────────────────────────────────────

    public function testChartsReturns200WithStructure(): void
    {
        $this->createAndCloseTrade($this->accountId, 18600, 'TP');

        $response = $this->router->dispatch($this->authRequest('GET', '/stats/charts'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('cumulative_pnl', $body['data']);
        $this->assertArrayHasKey('win_loss', $body['data']);
        $this->assertArrayHasKey('pnl_by_symbol', $body['data']);
    }

    // ── Advanced filters ────────────────────────────────────────

    public function testOverviewFiltersDateRange(): void
    {
        $this->createAndCloseTradeWithDate($this->accountId, 18600, 'TP', '2026-01-10 10:00:00');
        $this->createAndCloseTradeWithDate($this->accountId, 18400, 'SL', '2026-01-20 10:00:00');

        $response = $this->router->dispatch(
            $this->authRequest('GET', '/stats/overview', [], [
                'date_from' => '2026-01-08',
                'date_to' => '2026-01-12',
            ])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $body['data']['overview']['total_trades']);
    }

    public function testOverviewFiltersDirection(): void
    {
        $this->createAndCloseTradeWithOptions($this->accountId, 18600, 'TP', ['direction' => 'BUY']);
        $this->createAndCloseTradeWithOptions($this->accountId, 18600, 'TP', ['direction' => 'SELL']);

        $response = $this->router->dispatch(
            $this->authRequest('GET', '/stats/overview', [], ['direction' => 'SELL'])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $body['data']['overview']['total_trades']);
    }

    // Guard against a regression where a 100% SELL profitable account
    // would surface a negative avg R:R (reported 2026-04-23, unreproducible
    // on current data — this test pins the expected sign behavior so any
    // future refactor that breaks SELL direction math fails loudly).
    public function testAvgRrPositiveWhenAllSellsProfitable(): void
    {
        // Entry 18500, SL 50pts, size 1 → risk = 50.
        // SELLs profitable when exit < entry. Exits 18400/18450/18300
        // → PnL +100/+50/+200, R:R +2/+1/+4 → avg = +2.33.
        $this->createAndCloseTradeWithOptions($this->accountId, 18400, 'TP', ['direction' => 'SELL']);
        $this->createAndCloseTradeWithOptions($this->accountId, 18450, 'TP', ['direction' => 'SELL']);
        $this->createAndCloseTradeWithOptions($this->accountId, 18300, 'TP', ['direction' => 'SELL']);

        $overview = $this->router->dispatch($this->authRequest('GET', '/stats/overview'))->getBody();
        $this->assertSame(3, $overview['data']['overview']['total_trades']);
        $this->assertGreaterThan(0, $overview['data']['overview']['total_pnl']);
        $this->assertGreaterThan(0, $overview['data']['overview']['avg_rr']);

        $byDir = $this->router->dispatch($this->authRequest('GET', '/stats/by-direction'))->getBody();
        $sellRow = null;
        foreach ($byDir['data'] as $row) {
            if ($row['direction'] === 'SELL') {
                $sellRow = $row;
                break;
            }
        }
        $this->assertNotNull($sellRow, 'SELL group missing from by-direction stats');
        $this->assertSame(3, (int) $sellRow['total_trades']);
        $this->assertGreaterThan(0, (float) $sellRow['total_pnl']);
        $this->assertGreaterThan(0, (float) $sellRow['avg_rr']);
    }

    public function testChartsFiltersSymbols(): void
    {
        $this->createAndCloseTradeWithOptions($this->accountId, 18600, 'TP', ['symbol' => 'NASDAQ']);
        $this->createAndCloseTradeWithOptions($this->accountId, 18600, 'TP', ['symbol' => 'DAX']);
        $this->createAndCloseTradeWithOptions($this->accountId, 18600, 'TP', ['symbol' => 'EURUSD']);

        $response = $this->router->dispatch(
            $this->authRequest('GET', '/stats/charts', [], ['symbols' => 'NASDAQ,DAX'])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $body['data']['cumulative_pnl']);
    }

    public function testOverviewRejectsInvalidDirection(): void
    {
        try {
            $this->router->dispatch(
                $this->authRequest('GET', '/stats/overview', [], ['direction' => 'LONG'])
            );
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    // ── Dimension endpoints ─────────────────────────────────────

    public function testBySymbolReturns200WithStructure(): void
    {
        $this->createAndCloseTradeWithOptions($this->accountId, 18600, 'TP', ['symbol' => 'NASDAQ']);
        $this->createAndCloseTradeWithOptions($this->accountId, 18400, 'SL', ['symbol' => 'DAX']);

        $response = $this->router->dispatch($this->authRequest('GET', '/stats/by-symbol'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertCount(2, $body['data']);
        $this->assertArrayHasKey('symbol', $body['data'][0]);
        $this->assertArrayHasKey('total_trades', $body['data'][0]);
        $this->assertArrayHasKey('wins', $body['data'][0]);
        $this->assertArrayHasKey('win_rate', $body['data'][0]);
        $this->assertArrayHasKey('profit_factor', $body['data'][0]);
    }

    public function testByDirectionReturns200WithStructure(): void
    {
        $this->createAndCloseTradeWithOptions($this->accountId, 18600, 'TP', ['direction' => 'BUY']);
        $this->createAndCloseTradeWithOptions($this->accountId, 18400, 'SL', ['direction' => 'SELL']);

        $response = $this->router->dispatch($this->authRequest('GET', '/stats/by-direction'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $body['data']);
        $this->assertArrayHasKey('direction', $body['data'][0]);
    }

    public function testBySetupReturns200WithStructure(): void
    {
        $this->createAndCloseTrade($this->accountId, 18600, 'TP');

        $response = $this->router->dispatch($this->authRequest('GET', '/stats/by-setup'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['data']);
        $this->assertArrayHasKey('setup', $body['data'][0]);
    }

    public function testByPeriodReturns200WithGrouping(): void
    {
        $this->createAndCloseTrade($this->accountId, 18600, 'TP');

        $response = $this->router->dispatch(
            $this->authRequest('GET', '/stats/by-period', [], ['group' => 'month'])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertGreaterThanOrEqual(1, count($body['data']));
        $this->assertArrayHasKey('period', $body['data'][0]);
        $this->assertArrayHasKey('total_trades', $body['data'][0]);
    }

    public function testByPeriodRejectsInvalidGroup(): void
    {
        try {
            $this->router->dispatch(
                $this->authRequest('GET', '/stats/by-period', [], ['group' => 'invalid'])
            );
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testBySymbolSupportsAdvancedFilters(): void
    {
        $this->createAndCloseTradeWithOptions($this->accountId, 18600, 'TP', ['direction' => 'BUY', 'symbol' => 'NASDAQ']);
        $this->createAndCloseTradeWithOptions($this->accountId, 18600, 'TP', ['direction' => 'SELL', 'symbol' => 'NASDAQ']);

        $response = $this->router->dispatch(
            $this->authRequest('GET', '/stats/by-symbol', [], ['direction' => 'BUY'])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, (int) $body['data'][0]['total_trades']);
    }

    // ── R:R Distribution ──────────────────────────────────────

    public function testRrDistributionReturns200WithBuckets(): void
    {
        $this->createAndCloseTrade($this->accountId, 18600, 'TP');  // win
        $this->createAndCloseTrade($this->accountId, 18400, 'SL');  // loss

        $response = $this->router->dispatch($this->authRequest('GET', '/stats/rr-distribution'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertGreaterThanOrEqual(1, count($body['data']));
        $this->assertArrayHasKey('bucket', $body['data'][0]);
        $this->assertArrayHasKey('count', $body['data'][0]);
    }

    public function testRrDistributionSupportsFilters(): void
    {
        $this->createAndCloseTradeWithOptions($this->accountId, 18600, 'TP', ['symbol' => 'NASDAQ']);
        $this->createAndCloseTradeWithOptions($this->accountId, 18600, 'TP', ['symbol' => 'DAX']);

        $response = $this->router->dispatch(
            $this->authRequest('GET', '/stats/rr-distribution', [], ['symbols' => 'NASDAQ'])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $total = array_sum(array_column($body['data'], 'count'));
        $this->assertSame(1, $total);
    }

    // ── Heatmap ─────────────────────────────────────────────────

    public function testHeatmapReturns200WithDayHourGrid(): void
    {
        $this->createAndCloseTrade($this->accountId, 18600, 'TP');

        $response = $this->router->dispatch($this->authRequest('GET', '/stats/heatmap'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertGreaterThanOrEqual(1, count($body['data']));
        $this->assertArrayHasKey('day', $body['data'][0]);
        $this->assertArrayHasKey('hour', $body['data'][0]);
        $this->assertArrayHasKey('trade_count', $body['data'][0]);
        $this->assertArrayHasKey('total_pnl', $body['data'][0]);
    }

    public function testHeatmapSupportsFilters(): void
    {
        $this->createAndCloseTradeWithOptions($this->accountId, 18600, 'TP', ['direction' => 'BUY']);
        $this->createAndCloseTradeWithOptions($this->accountId, 18400, 'SL', ['direction' => 'SELL']);

        $response = $this->router->dispatch(
            $this->authRequest('GET', '/stats/heatmap', [], ['direction' => 'BUY'])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $total = array_sum(array_column($body['data'], 'trade_count'));
        $this->assertSame(1, $total);
    }

    // ── Empty state ─────────────────────────────────────────────

    public function testOverviewEmptyState(): void
    {
        $response = $this->router->dispatch($this->authRequest('GET', '/stats/overview'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['data']['overview']['total_trades']);
        $this->assertCount(0, $body['data']['recent_trades']);
    }
}
