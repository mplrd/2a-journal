<?php

namespace Tests\Unit\Services\Broker;

use App\Services\Broker\CredentialEncryptionService;
use PHPUnit\Framework\TestCase;

class CredentialEncryptionServiceTest extends TestCase
{
    private CredentialEncryptionService $service;

    protected function setUp(): void
    {
        // 32-byte key for AES-256
        $key = base64_encode(random_bytes(32));
        $this->service = new CredentialEncryptionService(base64_decode($key));
    }

    public function testEncryptReturnsArrayWithCiphertextAndIv(): void
    {
        $credentials = ['access_token' => 'abc123', 'refresh_token' => 'def456'];

        $result = $this->service->encrypt($credentials);

        $this->assertArrayHasKey('ciphertext', $result);
        $this->assertArrayHasKey('iv', $result);
        $this->assertNotEmpty($result['ciphertext']);
        $this->assertNotEmpty($result['iv']);
    }

    public function testDecryptReturnsOriginalCredentials(): void
    {
        $credentials = [
            'access_token' => 'abc123',
            'refresh_token' => 'def456',
            'ctid_trader_account_id' => 12345,
        ];

        $encrypted = $this->service->encrypt($credentials);
        $decrypted = $this->service->decrypt($encrypted['ciphertext'], $encrypted['iv']);

        $this->assertSame($credentials, $decrypted);
    }

    public function testEncryptProducesDifferentCiphertextEachTime(): void
    {
        $credentials = ['token' => 'same_value'];

        $result1 = $this->service->encrypt($credentials);
        $result2 = $this->service->encrypt($credentials);

        // Different IV → different ciphertext
        $this->assertNotSame($result1['ciphertext'], $result2['ciphertext']);
        $this->assertNotSame($result1['iv'], $result2['iv']);
    }

    public function testDecryptWithWrongKeyThrows(): void
    {
        $credentials = ['token' => 'secret'];
        $encrypted = $this->service->encrypt($credentials);

        // Create a different service with a different key
        $otherKey = random_bytes(32);
        $otherService = new CredentialEncryptionService($otherKey);

        $this->expectException(\RuntimeException::class);
        $otherService->decrypt($encrypted['ciphertext'], $encrypted['iv']);
    }

    public function testDecryptWithCorruptedCiphertextThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->decrypt('corrupted_data', base64_encode(random_bytes(16)));
    }

    public function testHandlesComplexCredentialStructure(): void
    {
        $credentials = [
            'api_token' => 'eyJhbGciOiJIUzI1NiJ9...',
            'metaapi_account_id' => 'abc-def-123',
            'login' => '12345678',
            'server' => 'FTMO-Server3',
        ];

        $encrypted = $this->service->encrypt($credentials);
        $decrypted = $this->service->decrypt($encrypted['ciphertext'], $encrypted['iv']);

        $this->assertSame($credentials, $decrypted);
    }
}
