<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class UserRepositoryTest extends TestCase
{
    private UserRepository $repo;
    private PDO $pdo;

    protected function setUp(): void
    {
        // Load .env for DB config
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
        $this->repo = new UserRepository($this->pdo);

        // Clean tables
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    public function testCreateUser(): void
    {
        $user = $this->repo->create([
            'email' => 'test@example.com',
            'password' => password_hash('Test1234', PASSWORD_BCRYPT),
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $this->assertArrayHasKey('id', $user);
        $this->assertSame('test@example.com', $user['email']);
        $this->assertSame('John', $user['first_name']);
        $this->assertSame('Doe', $user['last_name']);
        $this->assertArrayNotHasKey('password', $user);
    }

    public function testFindByEmail(): void
    {
        $this->repo->create([
            'email' => 'find@example.com',
            'password' => password_hash('Test1234', PASSWORD_BCRYPT),
        ]);

        $user = $this->repo->findByEmail('find@example.com');

        $this->assertNotNull($user);
        $this->assertSame('find@example.com', $user['email']);
        $this->assertArrayHasKey('password', $user);
    }

    public function testFindByEmailReturnsExplicitColumnsOnly(): void
    {
        $this->repo->create([
            'email' => 'cols@example.com',
            'password' => password_hash('Test1234', PASSWORD_BCRYPT),
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $user = $this->repo->findByEmail('cols@example.com');

        $expectedKeys = ['id', 'email', 'password', 'first_name', 'last_name', 'timezone', 'default_currency', 'locale', 'theme', 'created_at', 'updated_at'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $user);
        }
        $this->assertArrayNotHasKey('deleted_at', $user);
    }

    public function testFindByEmailReturnsNullWhenNotFound(): void
    {
        $user = $this->repo->findByEmail('nobody@example.com');

        $this->assertNull($user);
    }

    public function testFindById(): void
    {
        $created = $this->repo->create([
            'email' => 'byid@example.com',
            'password' => password_hash('Test1234', PASSWORD_BCRYPT),
        ]);

        $user = $this->repo->findById((int)$created['id']);

        $this->assertNotNull($user);
        $this->assertSame('byid@example.com', $user['email']);
        $this->assertArrayNotHasKey('password', $user);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $user = $this->repo->findById(999999);

        $this->assertNull($user);
    }

    public function testExistsByEmailReturnsTrue(): void
    {
        $this->repo->create([
            'email' => 'exists@example.com',
            'password' => password_hash('Test1234', PASSWORD_BCRYPT),
        ]);

        $this->assertTrue($this->repo->existsByEmail('exists@example.com'));
    }

    public function testExistsByEmailReturnsFalse(): void
    {
        $this->assertFalse($this->repo->existsByEmail('noone@example.com'));
    }

    public function testEmailIsUniqueConstraint(): void
    {
        $this->repo->create([
            'email' => 'unique@example.com',
            'password' => password_hash('Test1234', PASSWORD_BCRYPT),
        ]);

        $this->expectException(\PDOException::class);

        $this->repo->create([
            'email' => 'unique@example.com',
            'password' => password_hash('Test1234', PASSWORD_BCRYPT),
        ]);
    }

    public function testFindByIdReturnsBeThresholdPercent(): void
    {
        $created = $this->repo->create([
            'email' => 'thr@example.com',
            'password' => password_hash('Test1234', PASSWORD_BCRYPT),
        ]);

        $user = $this->repo->findById((int) $created['id']);

        $this->assertArrayHasKey('be_threshold_percent', $user);
        $this->assertEquals(0, (float) $user['be_threshold_percent']);
    }

    public function testUpdateProfileAcceptsBeThresholdPercent(): void
    {
        $created = $this->repo->create([
            'email' => 'thr-up@example.com',
            'password' => password_hash('Test1234', PASSWORD_BCRYPT),
        ]);

        $updated = $this->repo->updateProfile((int) $created['id'], [
            'be_threshold_percent' => 0.0500,
        ]);

        $this->assertEquals(0.0500, (float) $updated['be_threshold_percent']);
    }

    public function testSoftDeleteSetsDeletedAtAndHidesFromFinders(): void
    {
        $created = $this->repo->create([
            'email' => 'todelete@example.com',
            'password' => password_hash('Test1234', PASSWORD_BCRYPT),
        ]);
        $id = (int) $created['id'];

        $this->repo->softDelete($id);

        $this->assertNull($this->repo->findById($id));
        $this->assertNull($this->repo->findByEmail('todelete@example.com'));
        $this->assertFalse($this->repo->existsByEmail('todelete@example.com'));

        // Row still exists physically (soft delete)
        $stmt = $this->pdo->prepare('SELECT deleted_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        $this->assertNotFalse($row);
        $this->assertNotNull($row['deleted_at']);
    }

    public function testFindByIdExposesBillingColumns(): void
    {
        $created = $this->repo->create([
            'email' => 'bill@example.com',
            'password' => password_hash('Test1234', PASSWORD_BCRYPT),
        ]);

        $user = $this->repo->findById((int) $created['id']);

        $this->assertArrayHasKey('bypass_subscription', $user);
        $this->assertArrayHasKey('grace_period_end', $user);
        $this->assertArrayHasKey('stripe_customer_id', $user);
        $this->assertSame(0, (int) $user['bypass_subscription']);
    }

    public function testSetGracePeriodEnd(): void
    {
        $created = $this->repo->create([
            'email' => 'grace@example.com',
            'password' => password_hash('Test1234', PASSWORD_BCRYPT),
        ]);
        $id = (int) $created['id'];
        $end = date('Y-m-d H:i:s', time() + 14 * 86400);

        $this->repo->setGracePeriodEnd($id, $end);

        $user = $this->repo->findById($id);
        $this->assertSame($end, $user['grace_period_end']);
    }

    public function testSetStripeCustomerId(): void
    {
        $created = $this->repo->create([
            'email' => 'cust@example.com',
            'password' => password_hash('Test1234', PASSWORD_BCRYPT),
        ]);
        $id = (int) $created['id'];

        $this->repo->setStripeCustomerId($id, 'cus_test_ABC');

        $user = $this->repo->findById($id);
        $this->assertSame('cus_test_ABC', $user['stripe_customer_id']);
    }
}
