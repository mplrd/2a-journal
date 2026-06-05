<?php

namespace Tests\Integration\Notebook;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end HTTP flow for the notebook module: categories + notes CRUD, pin
 * filtering, ownership isolation and the "delete category detaches its notes"
 * contract — exercised through the real router and DB.
 */
class NotebookFlowTest extends TestCase
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
        $this->cleanTables();

        $router = new Router();
        require __DIR__ . '/../../../config/routes.php';
        $this->router = $router;

        $response = $this->router->dispatch(Request::create('POST', '/auth/register', [
            'email' => 'notebook@test.com',
            'password' => 'Test1234',
        ]));
        $this->accessToken = $response->getBody()['data']['access_token'];

        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => 'notebook@test.com']);
        $this->userId = (int) $stmt->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->cleanTables();
    }

    private function cleanTables(): void
    {
        $this->pdo->exec('DELETE FROM note_attachments');
        $this->pdo->exec('DELETE FROM notes');
        $this->pdo->exec('DELETE FROM note_categories');
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

    private function createCategory(string $label): int
    {
        $res = $this->router->dispatch($this->authRequest('POST', '/note-categories', ['label' => $label]));
        return (int) $res->getBody()['data']['id'];
    }

    private function createNote(array $body): array
    {
        $res = $this->router->dispatch($this->authRequest('POST', '/notes', $body));
        return $res->getBody()['data'];
    }

    // ── Categories ───────────────────────────────────────────────

    public function testCategoriesStartEmpty(): void
    {
        $res = $this->router->dispatch($this->authRequest('GET', '/note-categories'));
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame([], $res->getBody()['data']);
    }

    public function testCreateAndListCategory(): void
    {
        $this->createCategory('Money Management');
        $this->createCategory('Trading setup');

        $res = $this->router->dispatch($this->authRequest('GET', '/note-categories'));
        $labels = array_column($res->getBody()['data'], 'label');
        $this->assertSame(['Money Management', 'Trading setup'], $labels); // alpha order
    }

    public function testCreateCategoryRejectsDuplicate(): void
    {
        $this->createCategory('Psychologie');

        try {
            $this->router->dispatch($this->authRequest('POST', '/note-categories', ['label' => 'Psychologie']));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('note_categories.error.duplicate_label', $e->getMessageKey());
        }
    }

    // ── Notes ────────────────────────────────────────────────────

    public function testCreateNoteWithCategoryReturnsLabel(): void
    {
        $catId = $this->createCategory('Money Management');
        $note = $this->createNote([
            'title' => 'Trades de nuit',
            'content' => 'spread de 4 pts vs 1 + 2 pts / poz',
            'note_date' => '2026-06-01',
            'category_id' => $catId,
        ]);

        $this->assertSame('Trades de nuit', $note['title']);
        $this->assertSame($catId, (int) $note['category_id']);
        $this->assertSame('Money Management', $note['category_label']);
        $this->assertSame([], $note['attachments']);
    }

    public function testCreateNoteWithoutCategoryIsUncategorized(): void
    {
        $note = $this->createNote(['content' => 'sans catégorie', 'note_date' => '2026-06-02']);
        $this->assertNull($note['category_id']);
        $this->assertNull($note['category_label']);
    }

    public function testCreateNoteRejectsInvalidDate(): void
    {
        try {
            $this->router->dispatch($this->authRequest('POST', '/notes', [
                'content' => 'x', 'note_date' => '01/06/2026',
            ]));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('notes.error.invalid_date', $e->getMessageKey());
        }
    }

    public function testCreateNoteRejectsForeignCategory(): void
    {
        // Register another user, create a category under THEM
        $other = $this->router->dispatch(Request::create('POST', '/auth/register', [
            'email' => 'foreign@test.com', 'password' => 'Test1234',
        ]));
        $otherToken = $other->getBody()['data']['access_token'];
        $foreignCat = (int) $this->router->dispatch(Request::create('POST', '/note-categories', ['label' => 'Theirs'], [], [
            'Authorization' => "Bearer {$otherToken}",
        ]))->getBody()['data']['id'];

        try {
            $this->router->dispatch($this->authRequest('POST', '/notes', [
                'content' => 'x', 'note_date' => '2026-06-01', 'category_id' => $foreignCat,
            ]));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('notes.error.invalid_category', $e->getMessageKey());
        }
    }

    public function testPinnedFilterReturnsOnlyPinned(): void
    {
        $this->createNote(['content' => 'a', 'note_date' => '2026-06-01', 'is_pinned' => '1']);
        $this->createNote(['content' => 'b', 'note_date' => '2026-06-02']);

        $res = $this->router->dispatch($this->authRequest('GET', '/notes', [], ['pinned' => '1']));
        $data = $res->getBody()['data'];
        $this->assertCount(1, $data);
        $this->assertSame('a', $data[0]['content']);
    }

    public function testUpdateTogglesPinAndPersists(): void
    {
        $note = $this->createNote(['content' => 'toggle me', 'note_date' => '2026-06-01']);
        $id = (int) $note['id'];

        $res = $this->router->dispatch($this->authRequest('PUT', "/notes/{$id}", ['is_pinned' => true]));
        $this->assertSame(1, (int) $res->getBody()['data']['is_pinned']);

        $pinned = $this->router->dispatch($this->authRequest('GET', '/notes', [], ['pinned' => '1']))->getBody()['data'];
        $this->assertCount(1, $pinned);
    }

    public function testDeleteNoteRemovesFromList(): void
    {
        $note = $this->createNote(['content' => 'bye', 'note_date' => '2026-06-01']);
        $id = (int) $note['id'];

        $this->router->dispatch($this->authRequest('DELETE', "/notes/{$id}"));

        $list = $this->router->dispatch($this->authRequest('GET', '/notes'))->getBody()['data'];
        $this->assertCount(0, $list);
    }

    public function testDeleteCategoryDetachesNotes(): void
    {
        $catId = $this->createCategory('Temporaire');
        $note = $this->createNote(['content' => 'rattachée', 'note_date' => '2026-06-01', 'category_id' => $catId]);
        $id = (int) $note['id'];

        $this->router->dispatch($this->authRequest('DELETE', "/note-categories/{$catId}"));

        $reloaded = $this->router->dispatch($this->authRequest('GET', "/notes/{$id}"))->getBody()['data'];
        $this->assertNull($reloaded['category_id'], 'Note must fall back to "Autre" when its category is deleted');
        $this->assertNull($reloaded['category_label']);
    }

    // ── Ownership ────────────────────────────────────────────────

    public function testCannotReadOtherUsersNote(): void
    {
        $note = $this->createNote(['content' => 'secret', 'note_date' => '2026-06-01']);
        $id = (int) $note['id'];

        $other = $this->router->dispatch(Request::create('POST', '/auth/register', [
            'email' => 'intruder@test.com', 'password' => 'Test1234',
        ]));
        $otherToken = $other->getBody()['data']['access_token'];

        try {
            $this->router->dispatch(Request::create('GET', "/notes/{$id}", [], [], [
                'Authorization' => "Bearer {$otherToken}",
            ]));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function testRequiresAuth(): void
    {
        try {
            $this->router->dispatch(Request::create('GET', '/notes'));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }
}
