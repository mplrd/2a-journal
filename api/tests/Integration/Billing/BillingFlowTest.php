<?php

namespace Tests\Integration\Billing;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class BillingFlowTest extends TestCase
{
    private Router $router;
    private PDO $pdo;
    private string $accessToken;
    private int $userId;

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

        // Ensure a known webhook secret for signature tests
        putenv('STRIPE_WEBHOOK_SECRET=whsec_test_integration_secret');
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test_integration_secret';

        Database::reset();
        $this->pdo = Database::getConnection();

        $this->cleanup();

        $router = new Router();
        require __DIR__ . '/../../../config/routes.php';
        $this->router = $router;

        // Register user (registers with grace_period_end = NOW() + 14 days)
        $request = Request::create('POST', '/auth/register', [
            'email' => 'billing@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $this->accessToken = $response->getBody()['data']['access_token'];

        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => 'billing@test.com']);
        $this->userId = (int) $stmt->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->pdo->exec('DELETE FROM stripe_webhook_events');
        $this->pdo->exec('DELETE FROM subscriptions');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    private function authRequest(string $method, string $uri, array $body = []): Request
    {
        return Request::create($method, $uri, $body, [], [
            'Authorization' => "Bearer {$this->accessToken}",
        ]);
    }

    private function expireGracePeriod(): void
    {
        $past = date('Y-m-d H:i:s', time() - 86400);
        $this->pdo->prepare('UPDATE users SET grace_period_end = :t WHERE id = :id')
            ->execute(['t' => $past, 'id' => $this->userId]);
    }

    private function insertActiveSubscription(): void
    {
        $this->pdo->prepare(
            "INSERT INTO subscriptions (user_id, stripe_subscription_id, status, current_period_end, cancel_at_period_end)
             VALUES (:uid, :sid, 'active', :end, 0)"
        )->execute([
            'uid' => $this->userId,
            'sid' => 'sub_test_' . $this->userId,
            'end' => date('Y-m-d H:i:s', time() + 30 * 86400),
        ]);
    }

    // ── /billing/status ──────────────────────────────────────────

    public function testGetStatusRequiresAuth(): void
    {
        try {
            $this->router->dispatch(Request::create('GET', '/billing/status'));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testGetStatusReturnsGraceForNewUser(): void
    {
        $response = $this->router->dispatch($this->authRequest('GET', '/billing/status'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['data']['has_access']);
        $this->assertSame('grace_period', $body['data']['reason']);
        $this->assertNotNull($body['data']['grace_period_end']);
    }

    public function testGetStatusReturnsNoAccessWhenGraceExpired(): void
    {
        $this->expireGracePeriod();

        $response = $this->router->dispatch($this->authRequest('GET', '/billing/status'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($body['data']['has_access']);
        $this->assertSame('no_access', $body['data']['reason']);
    }

    public function testGetStatusReturnsSubscriptionActive(): void
    {
        $this->expireGracePeriod();
        $this->insertActiveSubscription();

        $response = $this->router->dispatch($this->authRequest('GET', '/billing/status'));
        $body = $response->getBody();

        $this->assertTrue($body['data']['has_access']);
        $this->assertSame('subscription_active', $body['data']['reason']);
        $this->assertSame('active', $body['data']['subscription']['status']);
    }

    public function testGetStatusReturnsBypassForBypassUser(): void
    {
        $this->pdo->prepare('UPDATE users SET bypass_subscription = 1 WHERE id = :id')
            ->execute(['id' => $this->userId]);

        $response = $this->router->dispatch($this->authRequest('GET', '/billing/status'));

        $this->assertSame('bypass', $response->getBody()['data']['reason']);
    }

    // ── Middleware gating on business endpoints ──────────────────

    public function testBusinessEndpointAllowedDuringGracePeriod(): void
    {
        // Fresh user → grace active → GET /accounts should return 200
        $response = $this->router->dispatch($this->authRequest('GET', '/accounts'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testBusinessEndpointReturns402WhenGraceExpiredAndNoSubscription(): void
    {
        $this->expireGracePeriod();

        try {
            $this->router->dispatch($this->authRequest('GET', '/accounts'));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(402, $e->getStatusCode());
            $this->assertSame('SUBSCRIPTION_REQUIRED', $e->getErrorCode());
            $this->assertSame('billing.error.subscription_required', $e->getMessageKey());
        }
    }

    public function testBusinessEndpointAllowedWithActiveSubscription(): void
    {
        $this->expireGracePeriod();
        $this->insertActiveSubscription();

        $response = $this->router->dispatch($this->authRequest('GET', '/accounts'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testBusinessEndpointAllowedForBypassUser(): void
    {
        $this->expireGracePeriod();
        $this->pdo->prepare('UPDATE users SET bypass_subscription = 1 WHERE id = :id')
            ->execute(['id' => $this->userId]);

        $response = $this->router->dispatch($this->authRequest('GET', '/accounts'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAuthEndpointsStayReachableWhenNoAccess(): void
    {
        $this->expireGracePeriod();

        // GET /auth/me must remain reachable so the front can detect the no-access state
        $response = $this->router->dispatch($this->authRequest('GET', '/auth/me'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testBillingEndpointsStayReachableWhenNoAccess(): void
    {
        $this->expireGracePeriod();

        $response = $this->router->dispatch($this->authRequest('GET', '/billing/status'));
        $this->assertSame(200, $response->getStatusCode());
    }

    // ── Webhook signature ────────────────────────────────────────

    public function testWebhookRejectsInvalidSignature(): void
    {
        $request = Request::create('POST', '/billing/webhook', [], [], [
            'Stripe-Signature' => 'invalid',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    public function testWebhookRejectsMissingSignature(): void
    {
        $request = Request::create('POST', '/billing/webhook');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }
}
