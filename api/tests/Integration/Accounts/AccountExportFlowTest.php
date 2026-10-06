<?php

namespace Tests\Integration\Accounts;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

/**
 * GET /accounts/{id}/export — the account and its closed trades as an .xlsx.
 */
class AccountExportFlowTest extends TestCase
{
    private Router $router;
    private PDO $pdo;

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
        $this->cleanTables();

        $router = new Router();
        require __DIR__ . '/../../../config/routes.php';
        $this->router = $router;
    }

    protected function tearDown(): void
    {
        $this->cleanTables();
    }

    private function cleanTables(): void
    {
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM partial_exits');
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM account_balance_adjustments');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    private function register(string $email): string
    {
        $response = $this->router->dispatch(Request::create('POST', '/auth/register', [
            'email' => $email,
            'password' => 'Test1234',
        ]));
        return $response->getBody()['data']['access_token'];
    }

    private function authRequest(string $token, string $method, string $uri, array $body = []): Request
    {
        return Request::create($method, $uri, $body, [], ['Authorization' => "Bearer {$token}"]);
    }

    private function createAccount(string $token, string $name = 'PF FTMO'): int
    {
        $response = $this->router->dispatch($this->authRequest($token, 'POST', '/accounts', [
            'name' => $name,
            'account_type' => 'PROP_FIRM',
            'stage' => 'CHALLENGE',
            'initial_capital' => 10000,
            'max_drawdown' => 1000,
        ]));
        return (int) $response->getBody()['data']['id'];
    }

    private function insertClosedTrade(int $accountId, float $pnl, string $openedAt, ?string $notes = null): void
    {
        $userId = (int) $this->pdo->query("SELECT user_id FROM accounts WHERE id = {$accountId}")->fetchColumn();
        $this->pdo->prepare(
            "INSERT INTO positions (user_id, account_id, direction, symbol, entry_price, size, setup, sl_points, notes, position_type)
             VALUES (:uid, :aid, 'BUY', 'GER40', 25000, 0.1, '[\"Break 5\"]', 100, :notes, 'TRADE')"
        )->execute(['uid' => $userId, 'aid' => $accountId, 'notes' => $notes]);
        $positionId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO trades (position_id, opened_at, closed_at, remaining_size, status, exit_type, pnl)
             VALUES (:pid, :opened, :closed, 0, 'CLOSED', 'SL', :pnl)"
        )->execute(['pid' => $positionId, 'opened' => $openedAt, 'closed' => $openedAt, 'pnl' => $pnl]);
    }

    /** The router throws; index.php is what turns the exception into a response. */
    private function dispatchExpectingError(Request $request): HttpException
    {
        try {
            $this->router->dispatch($request);
        } catch (HttpException $e) {
            return $e;
        }
        $this->fail('Expected HttpException');
    }

    private function readBook(string $bytes): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $bytes);
        try {
            return IOFactory::createReader('Xlsx')->load($path);
        } finally {
            @unlink($path);
        }
    }

    public function testExport_ownAccount_returnsAnXlsxWithBothTabs(): void
    {
        $token = $this->register('export@test.com');
        $this->pdo->exec("UPDATE users SET locale = 'fr' WHERE email = 'export@test.com'");
        $accountId = $this->createAccount($token);
        $this->insertClosedTrade($accountId, -13.10, '2026-09-10 16:54:44', '=1+1');
        $this->insertClosedTrade($accountId, 4.49, '2026-09-24 10:22:40');

        $response = $this->router->dispatch($this->authRequest($token, 'GET', "/accounts/{$accountId}/export"));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->getHeader('Content-Type')
        );
        $this->assertMatchesRegularExpression(
            '/^attachment; filename="pf-ftmo-\d{4}-\d{2}-\d{2}\.xlsx"$/',
            $response->getHeader('Content-Disposition')
        );

        $book = $this->readBook($response->getRawBody());
        $this->assertSame(['Compte', 'Trades', 'Ordres non exécutés'], $book->getSheetNames());

        $rows = $book->getSheetByName('Trades')->toArray(null, false, false);
        $this->assertCount(3, $rows);
        $first = array_combine($rows[0], $rows[1]);
        $this->assertSame('GER40', $first['Symbole']);
        $this->assertSame('Fermé', $first['Statut']);
        $this->assertSame('Achat', $first['Sens']);
        $this->assertEqualsWithDelta(-13.10, $first['P&L du trade'], 1e-9);
        $this->assertSame('=1+1', $first['Notes']);
    }

    public function testExport_inEnglish_whenTheUserLocaleIsEnglish(): void
    {
        $token = $this->register('export-en@test.com');
        $this->pdo->exec("UPDATE users SET locale = 'en' WHERE email = 'export-en@test.com'");
        $accountId = $this->createAccount($token);

        $response = $this->router->dispatch($this->authRequest($token, 'GET', "/accounts/{$accountId}/export"));

        $this->assertSame(['Account', 'Trades', 'Unexecuted orders'], $this->readBook($response->getRawBody())->getSheetNames());
    }

    public function testExport_anotherUsersAccount_isRefused(): void
    {
        $ownerToken = $this->register('owner@test.com');
        $accountId = $this->createAccount($ownerToken);
        $intruderToken = $this->register('intruder@test.com');

        $error = $this->dispatchExpectingError($this->authRequest($intruderToken, 'GET', "/accounts/{$accountId}/export"));

        $this->assertSame(403, $error->getStatusCode());
        $this->assertSame('accounts.error.forbidden', $error->getMessageKey());
    }

    public function testExport_aDeletedAccount_isNotFound(): void
    {
        $token = $this->register('deleted@test.com');
        $accountId = $this->createAccount($token);
        $this->router->dispatch($this->authRequest($token, 'DELETE', "/accounts/{$accountId}"));

        $error = $this->dispatchExpectingError($this->authRequest($token, 'GET', "/accounts/{$accountId}/export"));

        $this->assertSame(404, $error->getStatusCode());
        $this->assertSame('accounts.error.not_found', $error->getMessageKey());
    }

    public function testExport_withoutAToken_isUnauthorized(): void
    {
        $token = $this->register('anon@test.com');
        $accountId = $this->createAccount($token);

        $error = $this->dispatchExpectingError(Request::create('GET', "/accounts/{$accountId}/export"));

        $this->assertSame(401, $error->getStatusCode());
    }
}
