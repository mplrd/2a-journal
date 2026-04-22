<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Enums\ImportStatus;
use App\Repositories\ImportBatchRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class ImportBatchRepositoryTest extends TestCase
{
    private ImportBatchRepository $repo;
    private PDO $pdo;
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
        $this->repo = new ImportBatchRepository($this->pdo);

        $this->pdo->exec('DELETE FROM import_batches');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM users');

        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('import-test@test.com', 'hashed')");
        $this->userId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO accounts (user_id, name, currency) VALUES ({$this->userId}, 'Test Account', 'EUR')");
        $this->accountId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateAndFindBatch(): void
    {
        $id = $this->repo->create([
            'user_id' => $this->userId,
            'account_id' => $this->accountId,
            'broker_template' => 'ctrader',
            'original_filename' => 'cT_123_2026.xlsx',
            'file_hash' => hash('sha256', 'test'),
            'total_rows' => 10,
            'status' => ImportStatus::PENDING->value,
        ]);

        $batch = $this->repo->findById($id);

        $this->assertNotNull($batch);
        $this->assertSame($this->userId, (int) $batch['user_id']);
        $this->assertSame('ctrader', $batch['broker_template']);
        $this->assertSame('PENDING', $batch['status']);
        $this->assertSame(10, (int) $batch['total_rows']);
    }

    public function testListBatchesByUser(): void
    {
        $this->repo->create([
            'user_id' => $this->userId,
            'account_id' => $this->accountId,
            'original_filename' => 'file1.xlsx',
            'file_hash' => hash('sha256', 'test1'),
            'total_rows' => 5,
            'status' => ImportStatus::COMPLETED->value,
        ]);
        $this->repo->create([
            'user_id' => $this->userId,
            'account_id' => $this->accountId,
            'original_filename' => 'file2.xlsx',
            'file_hash' => hash('sha256', 'test2'),
            'total_rows' => 10,
            'status' => ImportStatus::COMPLETED->value,
        ]);

        $batches = $this->repo->findAllByUserId($this->userId);

        $this->assertCount(2, $batches);
    }

    public function testUpdateBatchStatus(): void
    {
        $id = $this->repo->create([
            'user_id' => $this->userId,
            'account_id' => $this->accountId,
            'original_filename' => 'file.xlsx',
            'file_hash' => hash('sha256', 'test'),
            'total_rows' => 5,
            'status' => ImportStatus::PENDING->value,
        ]);

        $this->repo->update($id, [
            'status' => ImportStatus::COMPLETED->value,
            'imported_positions' => 3,
            'imported_trades' => 3,
            'skipped_duplicates' => 2,
        ]);

        $batch = $this->repo->findById($id);
        $this->assertSame('COMPLETED', $batch['status']);
        $this->assertSame(3, (int) $batch['imported_positions']);
        $this->assertSame(2, (int) $batch['skipped_duplicates']);
    }
}
