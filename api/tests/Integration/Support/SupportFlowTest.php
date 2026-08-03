<?php

namespace Tests\Integration\Support;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class SupportFlowTest extends TestCase
{
    private Router $router;
    private PDO $pdo;
    private string $adminToken;
    private string $userToken;
    private string $otherToken;
    /** @var string[] absolute paths of attachment files to clean up */
    private array $storedFiles = [];

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
        $this->cleanup();

        $router = new Router();
        require __DIR__ . '/../../../config/routes.php';
        $this->router = $router;

        $this->register('admin@s.com');
        $this->pdo->prepare("UPDATE users SET role='ADMIN' WHERE email='admin@s.com'")->execute();
        $this->register('user@s.com');
        $this->register('other@s.com');

        $this->adminToken = $this->login('admin@s.com');
        $this->userToken = $this->login('user@s.com');
        $this->otherToken = $this->login('other@s.com');
    }

    protected function tearDown(): void
    {
        foreach ($this->storedFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->pdo->exec('DELETE FROM support_ticket_attachments');
        $this->pdo->exec('DELETE FROM support_ticket_messages');
        $this->pdo->exec('DELETE FROM support_tickets');
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec("DELETE FROM users WHERE email IN ('admin@s.com','user@s.com','other@s.com')");
    }

    private function register(string $email): void
    {
        $this->router->dispatch(Request::create('POST', '/auth/register', [
            'email' => $email, 'password' => 'Test1234',
        ]));
    }

    private function login(string $email): string
    {
        $res = $this->router->dispatch(Request::create('POST', '/auth/login', [
            'email' => $email, 'password' => 'Test1234',
        ]));
        return $res->getBody()['data']['access_token'];
    }

    private function req(string $token, string $method, string $uri, array $body = [], array $query = []): Request
    {
        return Request::create($method, $uri, $body, $query, ['Authorization' => "Bearer {$token}"]);
    }

    private function createTicket(string $token, array $body = []): array
    {
        $res = $this->router->dispatch($this->req($token, 'POST', '/support/tickets', array_merge([
            'type' => 'BUG', 'subject' => 'Cannot close trade', 'body' => 'Stack trace here',
        ], $body)));
        return $res->getBody();
    }

    // ── Creation ────────────────────────────────────────────────

    public function testUserCreatesTicketWithFirstMessage(): void
    {
        $body = $this->createTicket($this->userToken);

        $this->assertTrue($body['success']);
        $this->assertSame('BUG', $body['data']['type']);
        $this->assertSame('OPEN', $body['data']['status']);
        $this->assertSame('NORMAL', $body['data']['priority']);
        $this->assertCount(1, $body['data']['messages']);
        $this->assertSame('Stack trace here', $body['data']['messages'][0]['body']);
        $this->assertSame(0, (int) $body['data']['messages'][0]['author_is_admin']);
    }

    public function testBugTicketPersistsAndReturnsStructuredDetails(): void
    {
        $detail = $this->createTicket($this->userToken, [
            'type' => 'BUG',
            'subject' => 'Crash on close',
            'body' => 'app crashes',
            'details' => [
                'expected_behavior' => 'should close cleanly',
                'reproduction_steps' => "1. open trade\n2. click close",
                'not_allowed' => 'ignored',
            ],
        ])['data'];

        $this->assertIsArray($detail['details']);
        $this->assertSame('should close cleanly', $detail['details']['expected_behavior']);
        $this->assertArrayNotHasKey('not_allowed', $detail['details']);

        // Round-trips through the DB on a fresh read too.
        $reread = $this->router->dispatch(
            $this->req($this->userToken, 'GET', "/support/tickets/{$detail['id']}")
        )->getBody()['data'];
        $this->assertSame("1. open trade\n2. click close", $reread['details']['reproduction_steps']);
    }

    public function testSupportTicketHasNullDetails(): void
    {
        $detail = $this->createTicket($this->userToken, [
            'type' => 'SUPPORT', 'subject' => 'Q', 'body' => 'how?',
            'details' => ['expected_behavior' => 'nope'],
        ])['data'];

        $this->assertNull($detail['details']);
    }

    public function testCreateWorksWithoutActiveSubscription(): void
    {
        // Fresh registered users have no active subscription; the support routes
        // are mounted without the subscription gate, so this must succeed (201).
        $res = $this->router->dispatch($this->req($this->userToken, 'POST', '/support/tickets', [
            'type' => 'SUPPORT', 'subject' => 'Question', 'body' => 'How do I export?',
        ]));
        $this->assertSame(201, $res->getStatusCode());
    }

    public function testCreateRejectsInvalidType(): void
    {
        try {
            $this->router->dispatch($this->req($this->userToken, 'POST', '/support/tickets', [
                'type' => 'NONSENSE', 'subject' => 's', 'body' => 'b',
            ]));
            $this->fail('Expected 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testCreateRejectsAnonymous(): void
    {
        try {
            $this->router->dispatch(Request::create('POST', '/support/tickets', [
                'type' => 'BUG', 'subject' => 's', 'body' => 'b',
            ]));
            $this->fail('Expected 401');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── Listing & scoping ───────────────────────────────────────

    public function testUserListsOnlyOwnTickets(): void
    {
        $this->createTicket($this->userToken);
        $this->createTicket($this->otherToken);

        $res = $this->router->dispatch($this->req($this->userToken, 'GET', '/support/tickets'));
        $this->assertCount(1, $res->getBody()['data']);
    }

    public function testUserCannotSeeAnotherUsersTicket(): void
    {
        $ticket = $this->createTicket($this->userToken)['data'];

        try {
            $this->router->dispatch($this->req($this->otherToken, 'GET', "/support/tickets/{$ticket['id']}"));
            $this->fail('Expected 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // ── Admin thread & lifecycle ────────────────────────────────

    public function testAdminListsAllTicketsWithEmail(): void
    {
        $this->createTicket($this->userToken);
        $this->createTicket($this->otherToken);

        $res = $this->router->dispatch($this->req($this->adminToken, 'GET', '/admin/support/tickets'));
        $this->assertCount(2, $res->getBody()['data']);
        $this->assertArrayHasKey('user_email', $res->getBody()['data'][0]);
    }

    public function testAdminListFiltersByMultipleStatusesViaCsvQuery(): void
    {
        $open = $this->createTicket($this->userToken)['data'];
        $closed = $this->createTicket($this->userToken)['data'];
        $this->router->dispatch($this->req($this->adminToken, 'PATCH', "/admin/support/tickets/{$closed['id']}/status", [
            'status' => 'CLOSED',
        ]));
        $resolved = $this->createTicket($this->otherToken)['data'];
        $this->router->dispatch($this->req($this->adminToken, 'PATCH', "/admin/support/tickets/{$resolved['id']}/status", [
            'status' => 'RESOLVED',
        ]));

        // ?status=OPEN,CLOSED → the open one + the closed one, not the resolved.
        $res = $this->router->dispatch(
            $this->req($this->adminToken, 'GET', '/admin/support/tickets', [], ['status' => 'OPEN,CLOSED'])
        )->getBody();

        $this->assertSame(2, $res['meta']['total']);
        $ids = array_column($res['data'], 'id');
        $this->assertContains($open['id'], $ids);
        $this->assertContains($closed['id'], $ids);
        $this->assertNotContains($resolved['id'], $ids);
    }

    public function testAdminListRequiresAdminRole(): void
    {
        try {
            $this->router->dispatch($this->req($this->userToken, 'GET', '/admin/support/tickets'));
            $this->fail('Expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function testAdminReplyThenUserSeesIt(): void
    {
        $ticket = $this->createTicket($this->userToken)['data'];

        $this->router->dispatch($this->req($this->adminToken, 'POST', "/admin/support/tickets/{$ticket['id']}/messages", [
            'body' => 'We are investigating',
        ]));

        $detail = $this->router->dispatch($this->req($this->userToken, 'GET', "/support/tickets/{$ticket['id']}"))->getBody();
        $this->assertCount(2, $detail['data']['messages']);
        $this->assertSame('We are investigating', $detail['data']['messages'][1]['body']);
        $this->assertSame(1, (int) $detail['data']['messages'][1]['author_is_admin']);
    }

    public function testAdminChangesStatusStampsClosedAt(): void
    {
        $ticket = $this->createTicket($this->userToken)['data'];

        $res = $this->router->dispatch($this->req($this->adminToken, 'PATCH', "/admin/support/tickets/{$ticket['id']}/status", [
            'status' => 'CLOSED',
        ]));
        $this->assertSame('CLOSED', $res->getBody()['data']['status']);
        $this->assertNotNull($res->getBody()['data']['closed_at']);
    }

    public function testAdminChangesPriority(): void
    {
        $ticket = $this->createTicket($this->userToken)['data'];

        $res = $this->router->dispatch($this->req($this->adminToken, 'PATCH', "/admin/support/tickets/{$ticket['id']}/priority", [
            'priority' => 'HIGH',
        ]));
        $this->assertSame('HIGH', $res->getBody()['data']['priority']);
    }

    public function testAdminReclassifiesTicketType(): void
    {
        $ticket = $this->createTicket($this->userToken)['data'];

        $res = $this->router->dispatch($this->req($this->adminToken, 'PATCH', "/admin/support/tickets/{$ticket['id']}/type", [
            'type' => 'FEATURE',
        ]));
        $this->assertSame('FEATURE', $res->getBody()['data']['type']);

        // The owner sees the new classification on their own ticket.
        $detail = $this->router->dispatch($this->req($this->userToken, 'GET', "/support/tickets/{$ticket['id']}"))->getBody();
        $this->assertSame('FEATURE', $detail['data']['type']);
    }

    public function testAdminReclassifyRejectsUnknownType(): void
    {
        $ticket = $this->createTicket($this->userToken)['data'];

        try {
            $this->router->dispatch($this->req($this->adminToken, 'PATCH', "/admin/support/tickets/{$ticket['id']}/type", [
                'type' => 'QUESTION',
            ]));
            $this->fail('Expected 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testUserCannotReclassifyTicketType(): void
    {
        $ticket = $this->createTicket($this->userToken)['data'];

        try {
            $this->router->dispatch($this->req($this->userToken, 'PATCH', "/admin/support/tickets/{$ticket['id']}/type", [
                'type' => 'FEATURE',
            ]));
            $this->fail('Expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function testUserCannotReplyToClosedTicket(): void
    {
        $ticket = $this->createTicket($this->userToken)['data'];
        $this->router->dispatch($this->req($this->adminToken, 'PATCH', "/admin/support/tickets/{$ticket['id']}/status", [
            'status' => 'CLOSED',
        ]));

        try {
            $this->router->dispatch($this->req($this->userToken, 'POST', "/support/tickets/{$ticket['id']}/messages", [
                'body' => 'reopen pls',
            ]));
            $this->fail('Expected 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    // ── Attachments ─────────────────────────────────────────────

    public function testCreateWithAttachmentAndOwnershipOnDownload(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($tmp, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));

        $request = $this->req($this->userToken, 'POST', '/support/tickets', [
            'type' => 'BUG', 'subject' => 'With screenshot', 'body' => 'see attached',
        ]);
        $request->setFiles(['attachments' => [
            'name' => 'shot.png', 'type' => 'image/png', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp),
        ]]);

        $detail = $this->router->dispatch($request)->getBody()['data'];
        @unlink($tmp);

        $this->assertCount(1, $detail['attachments']);
        $attachment = $detail['attachments'][0];
        $this->assertSame('shot.png', $attachment['original_name']);
        $this->assertSame('image/png', $attachment['mime_type']);

        // Record the stored file for cleanup
        $this->storedFiles[] = dirname(__DIR__, 3) . '/storage/uploads/' . $attachment['stored_path'];

        // A different user must NOT be able to download it (ownership enforced)
        try {
            $this->router->dispatch(
                $this->req($this->otherToken, 'GET', "/support/tickets/{$detail['id']}/attachments/{$attachment['id']}")
            );
            $this->fail('Expected 404 for non-owner download');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
