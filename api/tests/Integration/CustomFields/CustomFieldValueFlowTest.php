<?php

namespace Tests\Integration\CustomFields;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class CustomFieldValueFlowTest extends TestCase
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

        $this->pdo->exec('DELETE FROM custom_field_values');
        $this->pdo->exec('DELETE FROM custom_field_definitions');
        $this->pdo->exec('DELETE FROM status_history');
        $this->pdo->exec('DELETE FROM partial_exits');
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM orders');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM setups');
        $this->pdo->exec('DELETE FROM symbols');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');

        $router = new Router();
        require __DIR__ . '/../../../config/routes.php';
        $this->router = $router;

        // Register user
        $response = $this->router->dispatch(Request::create('POST', '/auth/register', [
            'email' => 'cfvalue@test.com',
            'password' => 'Test1234',
        ]));
        $this->accessToken = $response->getBody()['data']['access_token'];

        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => 'cfvalue@test.com']);
        $this->userId = (int) $stmt->fetchColumn();

        // Create account
        $accResponse = $this->router->dispatch($this->authRequest('POST', '/accounts', [
            'name' => 'Test Account',
            'broker' => 'TestBroker',
            'account_type' => 'BROKER_DEMO',
        ]));
        $this->accountId = $accResponse->getBody()['data']['id'];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM custom_field_values');
        $this->pdo->exec('DELETE FROM custom_field_definitions');
        $this->pdo->exec('DELETE FROM status_history');
        $this->pdo->exec('DELETE FROM partial_exits');
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM orders');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM setups');
        $this->pdo->exec('DELETE FROM symbols');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    private function authRequest(string $method, string $uri, array $body = [], array $query = []): Request
    {
        return Request::create($method, $uri, $body, $query, [
            'Authorization' => "Bearer {$this->accessToken}",
        ]);
    }

    private function createTradeData(array $customFields = []): array
    {
        $data = [
            'account_id' => $this->accountId,
            'direction' => 'BUY',
            'symbol' => 'NAS100',
            'entry_price' => 18000,
            'size' => 1,
            'setup' => ['Breakout'],
            'sl_points' => 50,
            'opened_at' => '2025-01-15 09:30:00',
        ];

        if (!empty($customFields)) {
            $data['custom_fields'] = $customFields;
        }

        return $data;
    }

    // ── Create trade with custom fields ──────────────────────────

    public function testCreateTradeWithCustomFields(): void
    {
        // Create field definitions
        $boolField = $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
            'name' => 'Confident',
            'field_type' => 'BOOLEAN',
        ]))->getBody()['data'];

        $numField = $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
            'name' => 'Score',
            'field_type' => 'NUMBER',
        ]))->getBody()['data'];

        // Create trade with custom field values
        $tradeData = $this->createTradeData([
            ['field_id' => $boolField['id'], 'value' => 'true'],
            ['field_id' => $numField['id'], 'value' => '8.5'],
        ]);
        $response = $this->router->dispatch($this->authRequest('POST', '/trades', $tradeData));
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertArrayHasKey('custom_fields', $body['data']);
        $this->assertCount(2, $body['data']['custom_fields']);

        // Verify via GET
        $tradeId = $body['data']['id'];
        $getResponse = $this->router->dispatch($this->authRequest('GET', "/trades/{$tradeId}"));
        $getBody = $getResponse->getBody();

        $this->assertCount(2, $getBody['data']['custom_fields']);
        $cf = $getBody['data']['custom_fields'];
        $this->assertSame('Confident', $cf[0]['name']);
        $this->assertSame('true', $cf[0]['value']);
        $this->assertSame('Score', $cf[1]['name']);
        $this->assertSame('8.5', $cf[1]['value']);
    }

    public function testCreateTradeWithoutCustomFields(): void
    {
        $tradeData = $this->createTradeData();
        $response = $this->router->dispatch($this->authRequest('POST', '/trades', $tradeData));
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertArrayHasKey('custom_fields', $body['data']);
        $this->assertEmpty($body['data']['custom_fields']);
    }

    public function testCreateTradeWithInvalidCustomFieldValue(): void
    {
        $boolField = $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
            'name' => 'Flag',
            'field_type' => 'BOOLEAN',
        ]))->getBody()['data'];

        $tradeData = $this->createTradeData([
            ['field_id' => $boolField['id'], 'value' => 'maybe'],
        ]);

        try {
            $this->router->dispatch($this->authRequest('POST', '/trades', $tradeData));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('custom_fields.error.invalid_boolean', $e->getMessageKey());
        }
    }

    // ── Custom fields in trade list ──────────────────────────────

    public function testCustomFieldsReturnedInTradeList(): void
    {
        $textField = $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
            'name' => 'Notes',
            'field_type' => 'TEXT',
        ]))->getBody()['data'];

        $tradeData = $this->createTradeData([
            ['field_id' => $textField['id'], 'value' => 'Test note'],
        ]);
        $this->router->dispatch($this->authRequest('POST', '/trades', $tradeData));

        $listResponse = $this->router->dispatch($this->authRequest('GET', '/trades'));
        $body = $listResponse->getBody();

        $this->assertSame(200, $listResponse->getStatusCode());
        $this->assertNotEmpty($body['data']);
        $trade = $body['data'][0];
        $this->assertArrayHasKey('custom_fields', $trade);
        $this->assertCount(1, $trade['custom_fields']);
        $this->assertSame('Test note', $trade['custom_fields'][0]['value']);
    }

    // ── Cascade delete ───────────────────────────────────────────

    public function testCustomFieldValuesDeletedWithTrade(): void
    {
        $field = $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
            'name' => 'Tag',
            'field_type' => 'TEXT',
        ]))->getBody()['data'];

        $tradeData = $this->createTradeData([
            ['field_id' => $field['id'], 'value' => 'to delete'],
        ]);
        $createResponse = $this->router->dispatch($this->authRequest('POST', '/trades', $tradeData));
        $tradeId = $createResponse->getBody()['data']['id'];

        // Verify value exists
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM custom_field_values WHERE trade_id = :trade_id');
        $stmt->execute(['trade_id' => $tradeId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        // Delete trade
        $this->router->dispatch($this->authRequest('DELETE', "/trades/{$tradeId}"));

        // Verify values are gone
        $stmt->execute(['trade_id' => $tradeId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    // ── Select field validation ──────────────────────────────────

    public function testSelectFieldValidatesOptions(): void
    {
        $selectField = $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
            'name' => 'Mood',
            'field_type' => 'SELECT',
            'options' => ['Good', 'Bad'],
        ]))->getBody()['data'];

        // Valid option
        $tradeData = $this->createTradeData([
            ['field_id' => $selectField['id'], 'value' => 'Good'],
        ]);
        $response = $this->router->dispatch($this->authRequest('POST', '/trades', $tradeData));
        $this->assertSame(201, $response->getStatusCode());

        // Invalid option
        $tradeData2 = $this->createTradeData([
            ['field_id' => $selectField['id'], 'value' => 'Excellent'],
        ]);

        try {
            $this->router->dispatch($this->authRequest('POST', '/trades', $tradeData2));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('custom_fields.error.invalid_option', $e->getMessageKey());
        }
    }
}
