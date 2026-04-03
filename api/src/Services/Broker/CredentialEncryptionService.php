<?php

namespace App\Services\Broker;

class CredentialEncryptionService
{
    private const CIPHER = 'aes-256-cbc';

    private string $key;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    /**
     * Encrypt a credentials array.
     *
     * @return array{ciphertext: string, iv: string}
     */
    public function encrypt(array $credentials): array
    {
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);

        $ciphertext = openssl_encrypt(
            json_encode($credentials),
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
        ];
    }

    /**
     * Decrypt a ciphertext back to a credentials array.
     */
    public function decrypt(string $ciphertext, string $iv): array
    {
        $decrypted = openssl_decrypt(
            base64_decode($ciphertext),
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            base64_decode($iv)
        );

        if ($decrypted === false) {
            throw new \RuntimeException('Decryption failed');
        }

        $result = json_decode($decrypted, true);
        if (!is_array($result)) {
            throw new \RuntimeException('Decryption failed');
        }

        return $result;
    }
}
