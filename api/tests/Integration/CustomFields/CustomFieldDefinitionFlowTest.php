<?php

namespace Tests\Integration\CustomFields;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class CustomFieldDefinitionFlowTest extends TestCase
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

        Database::reset();
        $this->pdo = Database::getConnection();

        $this->pdo->exec('DELETE FROM custom_field_values');
        $this->pdo->exec('DELETE FROM custom_field_definitions');
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

        $request = Request::create('POST', '/auth/register', [
            'email' => 'customfields@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $data = $response->getBody()['data'];
        $this->accessToken = $data['access_token'];

        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => 'customfields@test.com']);
        $this->userId = (int) $stmt->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM custom_field_values');
        $this->pdo->exec('DELETE FROM custom_field_definitions');
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

    // ── Create ───────────────────────────────────────────────────

    public function testCreateBooleanFieldSuccess(): void
    {
        $request = $this->authRequest('POST', '/custom-fields', [
            'name' => 'Confident',
            'field_type' => 'BOOLEAN',
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('Confident', $body['data']['name']);
        $this->assertSame('BOOLEAN', $body['data']['field_type']);
        $this->assertNull($body['data']['options']);
    }

    public function testCreateSelectFieldSuccess(): void
    {
        $request = $this->authRequest('POST', '/custom-fields', [
            'name' => 'Mood',
            'field_type' => 'SELECT',
            'options' => ['Good', 'Neutral', 'Bad'],
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Mood', $body['data']['name']);
        $this->assertSame('SELECT', $body['data']['field_type']);
        $this->assertSame('["Good","Neutral","Bad"]', $body['data']['options']);
    }

    public function testCreateValidationError(): void
    {
        $request = $this->authRequest('POST', '/custom-fields', [
            'name' => '',
            'field_type' => 'TEXT',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('name', $e->getField());
        }
    }

    public function testCreateDuplicateName(): void
    {
        $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
            'name' => 'Score',
            'field_type' => 'NUMBER',
        ]));

        try {
            $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
                'name' => 'Score',
                'field_type' => 'NUMBER',
            ]));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('custom_fields.error.duplicate_name', $e->getMessageKey());
        }
    }

    public function testCreateRequiresAuth(): void
    {
        $request = Request::create('POST', '/custom-fields', [
            'name' => 'Test',
            'field_type' => 'TEXT',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── List ─────────────────────────────────────────────────────

    public function testListSuccess(): void
    {
        $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
            'name' => 'Field A',
            'field_type' => 'TEXT',
        ]));
        $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
            'name' => 'Field B',
            'field_type' => 'BOOLEAN',
        ]));

        $response = $this->router->dispatch($this->authRequest('GET', '/custom-fields'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertCount(2, $body['data']);
        $this->assertSame('Field A', $body['data'][0]['name']);
        $this->assertSame('Field B', $body['data'][1]['name']);
    }

    // ── Show ─────────────────────────────────────────────────────

    public function testShowSuccess(): void
    {
        $createResponse = $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
            'name' => 'Notes',
            'field_type' => 'TEXT',
        ]));
        $fieldId = $createResponse->getBody()['data']['id'];

        $response = $this->router->dispatch($this->authRequest('GET', "/custom-fields/{$fieldId}"));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Notes', $body['data']['name']);
    }

    // ── Update ───────────────────────────────────────────────────

    public function testUpdateSuccess(): void
    {
        $createResponse = $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
            'name' => 'Old Name',
            'field_type' => 'TEXT',
        ]));
        $fieldId = $createResponse->getBody()['data']['id'];

        $response = $this->router->dispatch($this->authRequest('PUT', "/custom-fields/{$fieldId}", [
            'name' => 'New Name',
        ]));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('New Name', $body['data']['name']);
    }

    // ── Delete ───────────────────────────────────────────────────

    public function testDeleteSuccess(): void
    {
        $createResponse = $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
            'name' => 'To Delete',
            'field_type' => 'BOOLEAN',
        ]));
        $fieldId = $createResponse->getBody()['data']['id'];

        $response = $this->router->dispatch($this->authRequest('DELETE', "/custom-fields/{$fieldId}"));
        $this->assertSame(200, $response->getStatusCode());

        $listResponse = $this->router->dispatch($this->authRequest('GET', '/custom-fields'));
        $names = array_column($listResponse->getBody()['data'], 'name');
        $this->assertNotContains('To Delete', $names);
    }

    // ── Ownership ────────────────────────────────────────────────

    public function testCannotAccessOtherUsersField(): void
    {
        $createResponse = $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
            'name' => 'Private',
            'field_type' => 'TEXT',
        ]));
        $fieldId = $createResponse->getBody()['data']['id'];

        $otherResponse = $this->router->dispatch(Request::create('POST', '/auth/register', [
            'email' => 'other@test.com',
            'password' => 'Test1234',
        ]));
        $otherToken = $otherResponse->getBody()['data']['access_token'];

        $request = Request::create('GET', "/custom-fields/{$fieldId}", [], [], [
            'Authorization' => "Bearer {$otherToken}",
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    // ── Sort order ───────────────────────────────────────────────

    public function testFieldsReturnedInSortOrder(): void
    {
        $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
            'name' => 'First',
            'field_type' => 'TEXT',
        ]));
        $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
            'name' => 'Second',
            'field_type' => 'BOOLEAN',
        ]));
        $this->router->dispatch($this->authRequest('POST', '/custom-fields', [
            'name' => 'Third',
            'field_type' => 'NUMBER',
        ]));

        $response = $this->router->dispatch($this->authRequest('GET', '/custom-fields'));
        $names = array_column($response->getBody()['data'], 'name');

        $this->assertSame(['First', 'Second', 'Third'], $names);
    }
}
