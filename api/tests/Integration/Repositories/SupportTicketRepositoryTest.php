<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Repositories\SupportTicketAttachmentRepository;
use App\Repositories\SupportTicketMessageRepository;
use App\Repositories\SupportTicketRepository;
use App\Repositories\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class SupportTicketRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SupportTicketRepository $tickets;
    private SupportTicketMessageRepository $messages;
    private SupportTicketAttachmentRepository $attachments;
    private UserRepository $users;
    private int $userId;
    private int $otherUserId;
    private int $adminId;

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
        $this->tickets = new SupportTicketRepository($this->pdo);
        $this->messages = new SupportTicketMessageRepository($this->pdo);
        $this->attachments = new SupportTicketAttachmentRepository($this->pdo);
        $this->users = new UserRepository($this->pdo);

        $this->cleanup();

        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('owner@test.com', 'h')");
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('other@test.com', 'h')");
        $this->otherUserId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO users (email, password, role) VALUES ('admin@test.com', 'h', 'ADMIN')");
        $this->adminId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->pdo->exec('DELETE FROM support_ticket_attachments');
        $this->pdo->exec('DELETE FROM support_ticket_messages');
        $this->pdo->exec('DELETE FROM support_tickets');
        $this->pdo->exec("DELETE FROM users WHERE email IN ('owner@test.com','other@test.com','admin@test.com')");
    }

    private function makeTicket(array $overrides = []): array
    {
        return $this->tickets->create(array_merge([
            'user_id' => $this->userId,
            'type' => TicketType::BUG->value,
            'subject' => 'Something broke',
        ], $overrides));
    }

    public function testCreateDefaultsStatusOpenPriorityNormal(): void
    {
        $ticket = $this->makeTicket();

        $this->assertSame(TicketStatus::OPEN->value, $ticket['status']);
        $this->assertSame(TicketPriority::NORMAL->value, $ticket['priority']);
        $this->assertSame(TicketType::BUG->value, $ticket['type']);
        $this->assertNull($ticket['closed_at']);
    }

    public function testFindByIdForUserScopesToOwner(): void
    {
        $ticket = $this->makeTicket();

        $this->assertNotNull($this->tickets->findByIdForUser((int) $ticket['id'], $this->userId));
        $this->assertNull($this->tickets->findByIdForUser((int) $ticket['id'], $this->otherUserId));
    }

    public function testFindAllByUserIdReturnsOnlyOwnTickets(): void
    {
        $this->makeTicket();
        $this->makeTicket(['user_id' => $this->otherUserId]);

        $result = $this->tickets->findAllByUserId($this->userId);
        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['items']);
    }

    public function testFindAllAdminFiltersByTypeAndJoinsEmail(): void
    {
        $this->makeTicket(['type' => TicketType::BUG->value]);
        $this->makeTicket(['type' => TicketType::FEATURE->value]);

        $all = $this->tickets->findAllAdmin();
        $this->assertSame(2, $all['total']);
        $this->assertArrayHasKey('user_email', $all['items'][0]);

        $bugs = $this->tickets->findAllAdmin(['type' => TicketType::BUG->value]);
        $this->assertSame(1, $bugs['total']);
    }

    public function testFindAllAdminFiltersByMultipleStatusesCsv(): void
    {
        $this->makeTicket(); // OPEN
        $inProgress = $this->makeTicket();
        $this->tickets->updateStatus((int) $inProgress['id'], TicketStatus::IN_PROGRESS->value, null);
        $closed = $this->makeTicket();
        $this->tickets->updateStatus((int) $closed['id'], TicketStatus::CLOSED->value, date('Y-m-d H:i:s'));

        // CSV multi-value: OPEN + IN_PROGRESS → 2 of the 3.
        $res = $this->tickets->findAllAdmin(['status' => 'OPEN,IN_PROGRESS']);
        $this->assertSame(2, $res['total']);

        $statuses = array_column($res['items'], 'status');
        $this->assertContains('OPEN', $statuses);
        $this->assertContains('IN_PROGRESS', $statuses);
        $this->assertNotContains('CLOSED', $statuses);
    }

    public function testFindAllAdminFiltersByMultipleTypesCsv(): void
    {
        $this->makeTicket(['type' => TicketType::BUG->value]);
        $this->makeTicket(['type' => TicketType::FEATURE->value]);
        $this->makeTicket(['type' => TicketType::SUPPORT->value]);

        $res = $this->tickets->findAllAdmin(['type' => 'BUG,FEATURE']);
        $this->assertSame(2, $res['total']);
    }

    public function testMultiValueFilterIgnoresUnknownValues(): void
    {
        $this->makeTicket(['type' => TicketType::BUG->value]);

        // Garbage values must be dropped, leaving only the valid one applied.
        $res = $this->tickets->findAllAdmin(['type' => 'BUG,NONSENSE']);
        $this->assertSame(1, $res['total']);

        // All-garbage → no valid value → filter not applied (returns everything).
        $this->makeTicket(['type' => TicketType::FEATURE->value]);
        $resAllBad = $this->tickets->findAllAdmin(['type' => 'NONSENSE,WAT']);
        $this->assertSame(2, $resAllBad['total']);
    }

    public function testUpdateStatusStampsClosedAt(): void
    {
        $ticket = $this->makeTicket();
        $closedAt = date('Y-m-d H:i:s');

        $updated = $this->tickets->updateStatus((int) $ticket['id'], TicketStatus::CLOSED->value, $closedAt);
        $this->assertSame(TicketStatus::CLOSED->value, $updated['status']);
        $this->assertNotNull($updated['closed_at']);
    }

    public function testUpdatePriority(): void
    {
        $ticket = $this->makeTicket();
        $updated = $this->tickets->updatePriority((int) $ticket['id'], TicketPriority::HIGH->value);
        $this->assertSame(TicketPriority::HIGH->value, $updated['priority']);
    }

    public function testMessagesThreadOrderedAndCounted(): void
    {
        $ticket = $this->makeTicket();
        $this->messages->create([
            'ticket_id' => $ticket['id'], 'author_id' => $this->userId,
            'author_is_admin' => false, 'body' => 'first',
        ]);
        $this->messages->create([
            'ticket_id' => $ticket['id'], 'author_id' => $this->adminId,
            'author_is_admin' => true, 'body' => 'reply',
        ]);

        $thread = $this->messages->findByTicketId((int) $ticket['id']);
        $this->assertCount(2, $thread);
        $this->assertSame('first', $thread[0]['body']);
        $this->assertSame(1, (int) $thread[1]['author_is_admin']);

        $list = $this->tickets->findAllByUserId($this->userId);
        $this->assertSame(2, (int) $list['items'][0]['message_count']);
    }

    public function testAttachmentsByTicket(): void
    {
        $ticket = $this->makeTicket();
        $this->attachments->create([
            'ticket_id' => $ticket['id'], 'message_id' => null,
            'stored_path' => 'tickets/abc.png', 'original_name' => 'shot.png',
            'mime_type' => 'image/png', 'size_bytes' => 1234,
        ]);

        $found = $this->attachments->findByTicketId((int) $ticket['id']);
        $this->assertCount(1, $found);
        $this->assertSame('shot.png', $found[0]['original_name']);
    }

    public function testFindAdminsReturnsOnlyAdmins(): void
    {
        $admins = $this->users->findAdmins();
        $emails = array_column($admins, 'email');

        $this->assertContains('admin@test.com', $emails);
        $this->assertNotContains('owner@test.com', $emails);
    }
}
