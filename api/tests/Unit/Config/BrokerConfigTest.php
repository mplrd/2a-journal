<?php

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Guards that `api/config/broker.php` fails fast when the encryption key
 * is missing or empty. Previously the file fell back to a hardcoded
 * 32-zero-byte key, which silently neutralized credential encryption.
 */
class BrokerConfigTest extends TestCase
{
    private const CONFIG_PATH = __DIR__ . '/../../../config/broker.php';

    /** @var string|null */
    private $originalEnv;

    protected function setUp(): void
    {
        $this->originalEnv = getenv('BROKER_ENCRYPTION_KEY') !== false
            ? getenv('BROKER_ENCRYPTION_KEY')
            : null;
    }

    protected function tearDown(): void
    {
        if ($this->originalEnv === null) {
            putenv('BROKER_ENCRYPTION_KEY');
        } else {
            putenv('BROKER_ENCRYPTION_KEY=' . $this->originalEnv);
        }
    }

    public function testLoadsConfigWhenKeyIsSet(): void
    {
        $validKey = base64_encode(random_bytes(32));
        putenv('BROKER_ENCRYPTION_KEY=' . $validKey);

        $config = include self::CONFIG_PATH;

        $this->assertIsArray($config);
        $this->assertArrayHasKey('encryption_key', $config);
        $this->assertSame(32, strlen($config['encryption_key']));
    }

    public function testThrowsWhenKeyMissing(): void
    {
        putenv('BROKER_ENCRYPTION_KEY');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/BROKER_ENCRYPTION_KEY/');

        include self::CONFIG_PATH;
    }

    public function testThrowsWhenKeyEmpty(): void
    {
        putenv('BROKER_ENCRYPTION_KEY=');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/BROKER_ENCRYPTION_KEY/');

        include self::CONFIG_PATH;
    }
}
