<?php

namespace Tests\Integration\Trades;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class TradeFlowTest extends TestCase
{
    private Router $router;
    private PDO $pdo;
    private string $accessToken;
    private int $userId;
    private int $accountId;

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
            'email' => 'trade@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $data = $response->getBody()['data'];
        $this->accessToken = $data['access_token'];

        // Get user ID
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => 'trade@test.com']);
        $this->userId = (int) $stmt->fetchColumn();

        // Create an account
        $response = $this->router->dispatch($this->authRequest('POST', '/accounts', [
            'name' => 'Test Account',
            'account_type' => 'BROKER_DEMO',
        ]));
        $this->accountId = (int) $response->getBody()['data']['id'];
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

    private function validTradeData(array $overrides = []): array
    {
        return array_merge([
            'account_id' => $this->accountId,
            'direction' => 'BUY',
            'symbol' => 'NASDAQ',
            'entry_price' => 18500,
            'size' => 2,
            'setup' => ['Breakout'],
            'sl_points' => 50,
            'opened_at' => '2026-01-15 10:00:00',
        ], $overrides);
    }

    private function createTrade(array $overrides = []): array
    {
        $response = $this->router->dispatch(
            $this->authRequest('POST', '/trades', $this->validTradeData($overrides))
        );
        return $response->getBody()['data'];
    }

    // ── Create ──────────────────────────────────────────────────

    public function testCreateTradeSuccess(): void
    {
        $response = $this->router->dispatch(
            $this->authRequest('POST', '/trades', $this->validTradeData())
        );
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('OPEN', $body['data']['status']);
        $this->assertSame('NASDAQ', $body['data']['symbol']);
        $this->assertSame('BUY', $body['data']['direction']);
        $this->assertSame('TRADE', $body['data']['position_type']);
        $this->assertEquals(2, (float) $body['data']['remaining_size']);

        // Verify SL price calculated (BUY: 18500 - 50 = 18450)
        $this->assertEquals(18450, (float) $body['data']['sl_price']);
    }

    public function testCreateTradeWithAllFields(): void
    {
        $response = $this->router->dispatch(
            $this->authRequest('POST', '/trades', $this->validTradeData([
                'be_points' => 30,
                'be_size' => 0.5,
                'notes' => 'Test notes',
                'targets' => [['id' => 'tp1', 'label' => 'TP1', 'points' => 100, 'size' => 0.5]],
            ]))
        );
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        // BUY: be_price = 18500 + 30 = 18530
        $this->assertEquals(18530, (float) $body['data']['be_price']);
    }

    public function testCreateTradeValidationError(): void
    {
        $request = $this->authRequest('POST', '/trades', [
            'account_id' => $this->accountId,
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testCreateTradeRequiresAuth(): void
    {
        $request = Request::create('POST', '/trades', $this->validTradeData());

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── List ────────────────────────────────────────────────────

    public function testListTradesEmpty(): void
    {
        $response = $this->router->dispatch($this->authRequest('GET', '/trades'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertCount(0, $body['data']);
    }

    public function testListTradesReturnsOwned(): void
    {
        $this->createTrade(['symbol' => 'NASDAQ']);
        $this->createTrade(['symbol' => 'DAX']);

        $response = $this->router->dispatch($this->authRequest('GET', '/trades'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $body['data']);
        $this->assertArrayHasKey('meta', $body);
        $this->assertSame(2, $body['meta']['total']);
    }

    public function testListTradesWithFilters(): void
    {
        $this->createTrade(['symbol' => 'NASDAQ']);
        $this->createTrade(['symbol' => 'DAX']);

        $response = $this->router->dispatch(
            $this->authRequest('GET', '/trades', [], ['symbol' => 'NASDAQ'])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['data']);
        $this->assertSame('NASDAQ', $body['data'][0]['symbol']);
    }

    public function testListFiltersByMultipleStatuses(): void
    {
        // OPEN
        $openTrade = $this->createTrade(['symbol' => 'NASDAQ']);
        // SECURED: create + BE-typed partial offload (only BE moves OPEN → SECURED;
        // a TP partial keeps the trade OPEN since the SL is still on the original level).
        $secTrade = $this->createTrade(['symbol' => 'NASDAQ', 'size' => 2]);
        $this->router->dispatch($this->authRequest('POST', "/trades/{$secTrade['id']}/close", [
            'exit_price' => 18500, 'exit_size' => 1, 'exit_type' => 'BE',
        ]));
        // CLOSED: create + full close
        $closedTrade = $this->createTrade(['symbol' => 'NASDAQ', 'size' => 1]);
        $this->router->dispatch($this->authRequest('POST', "/trades/{$closedTrade['id']}/close", [
            'exit_price' => 18600, 'exit_size' => 1, 'exit_type' => 'TP',
        ]));

        // statuses[]=OPEN&statuses[]=SECURED should return the 2 first, not the CLOSED one
        $response = $this->router->dispatch(
            $this->authRequest('GET', '/trades', [], ['statuses' => ['OPEN', 'SECURED']])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $body['data']);
        $returnedStatuses = array_column($body['data'], 'status');
        sort($returnedStatuses);
        $this->assertSame(['OPEN', 'SECURED'], $returnedStatuses);
    }

    public function testListFiltersBySingleStatusViaStatusesArray(): void
    {
        $this->createTrade(['symbol' => 'NASDAQ']);
        $closedTrade = $this->createTrade(['symbol' => 'DAX', 'size' => 1]);
        $this->router->dispatch($this->authRequest('POST', "/trades/{$closedTrade['id']}/close", [
            'exit_price' => 18600, 'exit_size' => 1, 'exit_type' => 'TP',
        ]));

        $response = $this->router->dispatch(
            $this->authRequest('GET', '/trades', [], ['statuses' => ['CLOSED']])
        );
        $body = $response->getBody();

        $this->assertCount(1, $body['data']);
        $this->assertSame('CLOSED', $body['data'][0]['status']);
    }

    public function testListKeepsSingleStatusBackwardCompat(): void
    {
        $this->createTrade(['symbol' => 'NASDAQ']);
        $closedTrade = $this->createTrade(['symbol' => 'DAX', 'size' => 1]);
        $this->router->dispatch($this->authRequest('POST', "/trades/{$closedTrade['id']}/close", [
            'exit_price' => 18600, 'exit_size' => 1, 'exit_type' => 'TP',
        ]));

        // Legacy single-status filter continues to work
        $response = $this->router->dispatch(
            $this->authRequest('GET', '/trades', [], ['status' => 'OPEN'])
        );
        $body = $response->getBody();

        $this->assertCount(1, $body['data']);
        $this->assertSame('OPEN', $body['data'][0]['status']);
    }

    public function testListIgnoresInvalidStatusesEntries(): void
    {
        $this->createTrade(['symbol' => 'NASDAQ']);

        // Malformed/unknown values must be filtered out rather than error
        $response = $this->router->dispatch(
            $this->authRequest('GET', '/trades', [], ['statuses' => ['OPEN', 'BOGUS', '']])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['data']);
        $this->assertSame('OPEN', $body['data'][0]['status']);
    }

    // ── Show ────────────────────────────────────────────────────

    public function testShowTradeSuccess(): void
    {
        $trade = $this->createTrade();

        $response = $this->router->dispatch($this->authRequest('GET', "/trades/{$trade['id']}"));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('NASDAQ', $body['data']['symbol']);
        $this->assertArrayHasKey('partial_exits', $body['data']);
    }

    public function testShowTradeNotFound(): void
    {
        $request = $this->authRequest('GET', '/trades/99999');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('trades.error.not_found', $e->getMessageKey());
        }
    }

    // ── Close (full lifecycle) ──────────────────────────────────

    public function testLifecycleCreatePartialCloseFinalClose(): void
    {
        // Create trade: BUY 2 lots NASDAQ @ 18500, SL 50pts
        $trade = $this->createTrade();
        $this->assertSame('OPEN', $trade['status']);
        $this->assertEquals(2.0, (float) $trade['remaining_size']);

        // BE-typed partial offload at entry price → SECURED.
        // BE means "SL moved to entry", so the remainder is no longer at risk.
        $response = $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                'exit_price' => 18500,
                'exit_size' => 1,
                'exit_type' => 'BE',
            ])
        );
        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('SECURED', $body['data']['status']);
        $this->assertEquals(1.0, (float) $body['data']['remaining_size']);

        // Final close: exit remaining 1 lot at 18650 → CLOSED
        $response = $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                'exit_price' => 18650,
                'exit_size' => 1,
                'exit_type' => 'TP',
            ])
        );
        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('CLOSED', $body['data']['status']);
        $this->assertEquals(0, (float) $body['data']['remaining_size']);

        // Verify PnL: BE 1 lot (0) + TP 1 lot (18650-18500)*1 = 0 + 150 = 150
        $this->assertEquals(150.0, (float) $body['data']['pnl']);

        // Verify avg exit price: (18500*1 + 18650*1) / 2 = 18575
        $this->assertEquals(18575.0, (float) $body['data']['avg_exit_price']);
    }

    public function testCloseTradeCalculatesPnlBuy(): void
    {
        $trade = $this->createTrade(['size' => 1]);

        $response = $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                'exit_price' => 18600,
                'exit_size' => 1,
                'exit_type' => 'TP',
            ])
        );
        $body = $response->getBody();

        // BUY: PnL = (18600 - 18500) * 1 * 1 = 100
        $this->assertEquals(100.0, (float) $body['data']['pnl']);
        $this->assertSame('CLOSED', $body['data']['status']);
    }

    public function testUpdateEntryPriceRecomputesPartialAndTradePnl(): void
    {
        // BUY 2 @18500, partial 1 @18600 → realized 100.
        $trade = $this->createTrade(['size' => 2]);
        $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                'exit_price' => 18600, 'exit_size' => 1, 'exit_type' => 'TP',
            ])
        );

        $afterPartial = $this->router->dispatch($this->authRequest('GET', "/trades/{$trade['id']}"));
        $body = $afterPartial->getBody()['data'];
        $this->assertEquals(100.0, (float) $body['pnl']);
        $this->assertEquals(100.0, (float) $body['partial_exits'][0]['pnl']);

        // Edit entry_price down to 18400. Partial pnl should follow:
        // (18600 - 18400) * 1 * 1 = 200. Trade pnl too.
        $this->router->dispatch(
            $this->authRequest('PUT', "/trades/{$trade['id']}", [
                'entry_price' => 18400,
            ])
        );

        $afterEdit = $this->router->dispatch($this->authRequest('GET', "/trades/{$trade['id']}"));
        $body = $afterEdit->getBody()['data'];
        $this->assertEquals(200.0, (float) $body['partial_exits'][0]['pnl']);
        $this->assertEquals(200.0, (float) $body['pnl']);
        // pnl_percent = 200 / (18400 * 2) * 100 ≈ 0.5435
        $this->assertEqualsWithDelta(0.5435, (float) $body['pnl_percent'], 0.001);
        // risk_reward = 200 / (2 * 50) = 2.0
        $this->assertEquals(2.0, (float) $body['risk_reward']);
    }

    public function testCloseTradeCalculatesPnlSell(): void
    {
        $trade = $this->createTrade(['direction' => 'SELL', 'size' => 1]);

        $response = $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                'exit_price' => 18400,
                'exit_size' => 1,
                'exit_type' => 'TP',
            ])
        );
        $body = $response->getBody();

        // SELL: PnL = (18400 - 18500) * 1 * -1 = 100
        $this->assertEquals(100.0, (float) $body['data']['pnl']);
    }

    public function testCloseTradeAlreadyClosed(): void
    {
        $trade = $this->createTrade(['size' => 1]);

        // Close the trade
        $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                'exit_price' => 18600,
                'exit_size' => 1,
                'exit_type' => 'TP',
            ])
        );

        // Try to close again
        try {
            $this->router->dispatch(
                $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                    'exit_price' => 18600,
                    'exit_size' => 1,
                    'exit_type' => 'TP',
                ])
            );
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('trades.error.already_closed', $e->getMessageKey());
        }
    }

    public function testCloseTradeStatusHistory(): void
    {
        $trade = $this->createTrade(['size' => 1]);

        // Close fully
        $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                'exit_price' => 18600,
                'exit_size' => 1,
                'exit_type' => 'TP',
            ])
        );

        // Verify status history: OPEN → ... → CLOSED
        $stmt = $this->pdo->prepare(
            "SELECT * FROM status_history WHERE entity_type = 'TRADE' AND entity_id = :id ORDER BY id"
        );
        $stmt->execute(['id' => $trade['id']]);
        $history = $stmt->fetchAll();

        // First: null → OPEN (from create), Last: OPEN → CLOSED
        $this->assertGreaterThanOrEqual(2, count($history));
        $this->assertSame('OPEN', $history[0]['new_status']);
        $last = end($history);
        $this->assertSame('CLOSED', $last['new_status']);
    }

    public function testCloseTradeWithTargetIdStoresIt(): void
    {
        $trade = $this->createTrade([
            'size' => 2,
            'targets' => [['id' => 'tp1', 'label' => 'TP1', 'points' => 100, 'size' => 1]],
        ]);

        $response = $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                'exit_price' => 18600,
                'exit_size' => 1,
                'exit_type' => 'TP',
                'target_id' => 'tp1',
            ])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['data']['partial_exits']);
        $this->assertSame('tp1', $body['data']['partial_exits'][0]['target_id']);
    }

    public function testListTradesIncludesPartialExits(): void
    {
        $trade = $this->createTrade(['size' => 2]);

        // Create a partial exit
        $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                'exit_price' => 18600,
                'exit_size' => 1,
                'exit_type' => 'TP',
            ])
        );

        $response = $this->router->dispatch($this->authRequest('GET', '/trades'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['data']);
        $this->assertArrayHasKey('partial_exits', $body['data'][0]);
        $this->assertCount(1, $body['data'][0]['partial_exits']);
        $this->assertSame('TP', $body['data'][0]['partial_exits'][0]['exit_type']);
    }

    // ── Update ──────────────────────────────────────────────────

    public function testUpdateTradeOpenedAt(): void
    {
        $trade = $this->createTrade();

        $response = $this->router->dispatch(
            $this->authRequest('PUT', "/trades/{$trade['id']}", [
                'opened_at' => '2026-02-01 09:30:00',
            ])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('2026-02-01 09:30:00', $body['data']['opened_at']);
    }

    public function testUpdateTradePositionFields(): void
    {
        $trade = $this->createTrade();

        $response = $this->router->dispatch(
            $this->authRequest('PUT', "/trades/{$trade['id']}", [
                'entry_price' => 19000,
                'sl_points' => 80,
                'notes' => 'Updated entry after re-analysis',
            ])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEquals(19000, (float) $body['data']['entry_price']);
        // BUY: sl_price = entry - sl_points = 19000 - 80 = 18920
        $this->assertEquals(18920, (float) $body['data']['sl_price']);
        $this->assertSame('Updated entry after re-analysis', $body['data']['notes']);
    }

    public function testUpdateTradeClosedAtRequiresClosedStatus(): void
    {
        $trade = $this->createTrade();

        try {
            $this->router->dispatch(
                $this->authRequest('PUT', "/trades/{$trade['id']}", [
                    'closed_at' => '2026-02-01 16:00:00',
                ])
            );
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testUpdateTradeRejectsCrossUserAccess(): void
    {
        $trade = $this->createTrade();

        // Create a second user and use their token
        $otherEmail = 'other-' . uniqid() . '@test.com';
        $register = $this->router->dispatch(
            Request::create('POST', '/auth/register', [
                'email' => $otherEmail,
                'password' => 'Test1234!',
                'first_name' => 'Other',
                'last_name' => 'User',
            ])
        );
        $otherToken = $register->getBody()['data']['access_token'];

        try {
            $this->router->dispatch(
                Request::create('PUT', "/trades/{$trade['id']}", [
                    'entry_price' => 99999,
                ], [], ['Authorization' => "Bearer {$otherToken}"])
            );
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertContains($e->getStatusCode(), [403, 404]);
        }
    }

    public function testUpdateTradeRequiresAuth(): void
    {
        $trade = $this->createTrade();

        $request = Request::create('PUT', "/trades/{$trade['id']}", [
            'entry_price' => 19000,
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── Delete ──────────────────────────────────────────────────

    // ── BE Hit ──────────────────────────────────────────────────

    public function testMarkBeReached(): void
    {
        $trade = $this->createTrade([
            'be_points' => 30,
        ]);

        $this->assertSame(0, (int) $trade['be_reached']);

        $response = $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/be-hit")
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame(1, (int) $body['data']['be_reached']);
        $this->assertArrayHasKey('partial_exits', $body['data']);
    }

    public function testMarkBeReachedTransitionsOpenToSecured(): void
    {
        $trade = $this->createTrade(['be_points' => 30]);

        $this->assertSame('OPEN', $trade['status']);

        $response = $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/be-hit")
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('SECURED', $body['data']['status']);
        $this->assertSame(1, (int) $body['data']['be_reached']);
    }

    public function testMarkBeReachedAlreadyClosed(): void
    {
        $trade = $this->createTrade(['size' => 1]);

        // Close the trade
        $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                'exit_price' => 18600,
                'exit_size' => 1,
                'exit_type' => 'TP',
            ])
        );

        // Try to mark BE reached on closed trade
        try {
            $this->router->dispatch(
                $this->authRequest('POST', "/trades/{$trade['id']}/be-hit")
            );
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('trades.error.already_closed', $e->getMessageKey());
        }
    }

    // ── Delete ──────────────────────────────────────────────────

    public function testDeleteTradeSuccess(): void
    {
        $trade = $this->createTrade();

        $response = $this->router->dispatch(
            $this->authRequest('DELETE', "/trades/{$trade['id']}")
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getBody()['success']);

        // Verify it's gone
        try {
            $this->router->dispatch($this->authRequest('GET', "/trades/{$trade['id']}"));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        // Verify position is also gone (CASCADE)
        $stmt = $this->pdo->prepare('SELECT * FROM positions WHERE id = :id');
        $stmt->execute(['id' => $trade['position_id']]);
        $this->assertFalse($stmt->fetch());
    }

    public function testDeleteTradeForbidden(): void
    {
        $trade = $this->createTrade();

        // Register another user
        $response = $this->router->dispatch(
            Request::create('POST', '/auth/register', ['email' => 'other@test.com', 'password' => 'Test1234'])
        );
        $otherToken = $response->getBody()['data']['access_token'];

        $request = Request::create('DELETE', "/trades/{$trade['id']}", [], [], [
            'Authorization' => "Bearer {$otherToken}",
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    // ── Bulk delete ──────────────────────────────────────────────

    public function testBulkDeleteTradesSuccess(): void
    {
        $t1 = $this->createTrade();
        $t2 = $this->createTrade();
        $t3 = $this->createTrade();

        $response = $this->router->dispatch(
            $this->authRequest('POST', '/trades/bulk-delete', [
                'ids' => [$t1['id'], $t2['id'], $t3['id']],
            ])
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertSame(3, $body['data']['deleted_count']);
        $this->assertSame('trades.success.bulk_deleted', $body['data']['message_key']);

        // All 3 trades + their parent positions are gone (CASCADE).
        foreach ([$t1, $t2, $t3] as $t) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM trades WHERE id = :id');
            $stmt->execute(['id' => $t['id']]);
            $this->assertFalse($stmt->fetch());
        }
    }

    public function testBulkDeleteForbiddenWhenAnyIdBelongsToAnotherUser(): void
    {
        $mine = $this->createTrade();

        // Register another user and create a trade for them
        $response = $this->router->dispatch(
            Request::create('POST', '/auth/register', ['email' => 'other@test.com', 'password' => 'Test1234'])
        );
        $otherToken = $response->getBody()['data']['access_token'];
        $otherUserStmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $otherUserStmt->execute(['email' => 'other@test.com']);
        $otherUserId = (int) $otherUserStmt->fetchColumn();

        // Create an account + position + trade for the other user (raw SQL,
        // simpler than re-registering through the API).
        $this->pdo->prepare('INSERT INTO accounts (user_id, name, currency, initial_capital, current_capital) VALUES (?, ?, ?, ?, ?)')
            ->execute([$otherUserId, 'Other Acc', 'EUR', 10000, 10000]);
        $otherAccountId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO positions (user_id, account_id, direction, symbol, entry_price, size, position_type) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$otherUserId, $otherAccountId, 'BUY', 'NAS100', 18000, 1, 'TRADE']);
        $otherPositionId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO trades (position_id, opened_at, remaining_size, status) VALUES (?, ?, ?, ?)')
            ->execute([$otherPositionId, '2026-01-15 10:00:00', 1, 'OPEN']);
        $otherTradeId = (int) $this->pdo->lastInsertId();

        try {
            $this->router->dispatch(
                $this->authRequest('POST', '/trades/bulk-delete', [
                    'ids' => [$mine['id'], $otherTradeId],
                ])
            );
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Both trades still exist (rolled back / never started).
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM trades WHERE id IN (?, ?)');
        $stmt->execute([$mine['id'], $otherTradeId]);
        $this->assertSame(2, (int) $stmt->fetchColumn());
    }

    public function testBulkDeleteEmptyIdsValidation(): void
    {
        try {
            $this->router->dispatch(
                $this->authRequest('POST', '/trades/bulk-delete', ['ids' => []])
            );
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('trades.error.bulk_delete_empty', $e->getMessageKey());
        }
    }

    public function testListTradesFilterByDateRange(): void
    {
        // Create 3 trades with explicit opened_at by patching after create.
        $t1 = $this->createTrade();
        $t2 = $this->createTrade();
        $t3 = $this->createTrade();

        $this->pdo->prepare('UPDATE trades SET opened_at = ? WHERE id = ?')->execute(['2026-01-10 10:00:00', $t1['id']]);
        $this->pdo->prepare('UPDATE trades SET opened_at = ? WHERE id = ?')->execute(['2026-01-15 10:00:00', $t2['id']]);
        $this->pdo->prepare('UPDATE trades SET opened_at = ? WHERE id = ?')->execute(['2026-01-20 10:00:00', $t3['id']]);

        $response = $this->router->dispatch(
            $this->authRequest('GET', '/trades', [], [
                'date_from' => '2026-01-12',
                'date_to' => '2026-01-18',
            ])
        );

        $body = $response->getBody();
        $ids = array_map(fn($t) => $t['id'], $body['data']);
        $this->assertContains($t2['id'], $ids);
        $this->assertNotContains($t1['id'], $ids);
        $this->assertNotContains($t3['id'], $ids);
    }
}
