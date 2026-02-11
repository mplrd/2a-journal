<?php

namespace Tests\Unit\Services;

use App\Exceptions\HttpException;
use App\Exceptions\UnauthorizedException;
use App\Exceptions\ValidationException;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use PHPUnit\Framework\TestCase;

class AuthServiceTest extends TestCase
{
    private AuthService $service;
    private UserRepository $userRepo;
    private RefreshTokenRepository $tokenRepo;
    private array $config;

    protected function setUp(): void
    {
        $this->config = [
            'jwt_secret' => 'test-secret-key-for-testing-only',
            'jwt_algo' => 'HS256',
            'access_token_ttl' => 900,
            'refresh_token_ttl' => 604800,
            'password_min_length' => 8,
            'bcrypt_cost' => 12,
        ];

        $this->userRepo = $this->createMock(UserRepository::class);
        $this->tokenRepo = $this->createMock(RefreshTokenRepository::class);

        $this->service = new AuthService($this->userRepo, $this->tokenRepo, $this->config);
    }

    // ── Register validation ──────────────────────────────────────

    public function testRegisterThrowsWhenEmailMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.field_required');

        $this->service->register(['password' => 'Test1234']);
    }

    public function testRegisterThrowsWhenPasswordMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.field_required');

        $this->service->register(['email' => 'test@test.com']);
    }

    public function testRegisterThrowsWhenEmailInvalid(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.invalid_email');

        $this->service->register(['email' => 'not-an-email', 'password' => 'Test1234']);
    }

    public function testRegisterThrowsWhenPasswordTooWeak(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.password_too_weak');

        $this->service->register(['email' => 'test@test.com', 'password' => 'weak']);
    }

    public function testRegisterThrowsWhenPasswordHasNoUppercase(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.password_too_weak');

        $this->service->register(['email' => 'test@test.com', 'password' => 'test1234']);
    }

    public function testRegisterThrowsWhenPasswordHasNoLowercase(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.password_too_weak');

        $this->service->register(['email' => 'test@test.com', 'password' => 'TEST1234']);
    }

    public function testRegisterThrowsWhenPasswordHasNoDigit(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.password_too_weak');

        $this->service->register(['email' => 'test@test.com', 'password' => 'TestTest']);
    }

    public function testRegisterThrowsWhenEmailTaken(): void
    {
        $this->userRepo->method('existsByEmail')->willReturn(true);

        try {
            $this->service->register(['email' => 'taken@test.com', 'password' => 'Test1234']);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('EMAIL_TAKEN', $e->getErrorCode());
            $this->assertSame('auth.error.email_taken', $e->getMessageKey());
            $this->assertSame('email', $e->getField());
        }
    }

    public function testRegisterSuccess(): void
    {
        $this->userRepo->method('existsByEmail')->willReturn(false);
        $this->userRepo->method('create')->willReturn([
            'id' => 1,
            'email' => 'new@test.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $result = $this->service->register([
            'email' => 'new@test.com',
            'password' => 'Test1234',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertArrayHasKey('user', $result);
        $this->assertSame('new@test.com', $result['user']['email']);
    }

    // ── Login ────────────────────────────────────────────────────

    public function testLoginThrowsWhenEmailMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.field_required');

        $this->service->login(['password' => 'Test1234']);
    }

    public function testLoginThrowsWhenPasswordMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.field_required');

        $this->service->login(['email' => 'test@test.com']);
    }

    public function testLoginThrowsWhenEmailNotFound(): void
    {
        $this->userRepo->method('findByEmail')->willReturn(null);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('auth.error.invalid_credentials');

        $this->service->login(['email' => 'nobody@test.com', 'password' => 'Test1234']);
    }

    public function testLoginThrowsWhenPasswordWrong(): void
    {
        $this->userRepo->method('findByEmail')->willReturn([
            'id' => 1,
            'email' => 'test@test.com',
            'password' => password_hash('Correct1', PASSWORD_BCRYPT),
        ]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('auth.error.invalid_credentials');

        $this->service->login(['email' => 'test@test.com', 'password' => 'Wrong123']);
    }

    public function testLoginSuccess(): void
    {
        $hashedPassword = password_hash('Test1234', PASSWORD_BCRYPT);
        $this->userRepo->method('findByEmail')->willReturn([
            'id' => 1,
            'email' => 'test@test.com',
            'password' => $hashedPassword,
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        $this->userRepo->method('findById')->willReturn([
            'id' => 1,
            'email' => 'test@test.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $result = $this->service->login(['email' => 'test@test.com', 'password' => 'Test1234']);

        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertArrayHasKey('user', $result);
    }

    // ── Refresh ──────────────────────────────────────────────────

    public function testRefreshThrowsWhenTokenMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.field_required');

        $this->service->refresh([]);
    }

    public function testRefreshThrowsWhenTokenNotFound(): void
    {
        $this->tokenRepo->method('findByToken')->willReturn(null);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('auth.error.refresh_token_invalid');

        $this->service->refresh(['refresh_token' => 'bad-token']);
    }

    public function testRefreshThrowsWhenTokenExpired(): void
    {
        $this->tokenRepo->method('findByToken')->willReturn([
            'id' => 1,
            'user_id' => 1,
            'token' => 'expired-token',
            'expires_at' => '2020-01-01 00:00:00',
        ]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('auth.error.refresh_token_invalid');

        $this->service->refresh(['refresh_token' => 'expired-token']);
    }

    public function testRefreshSuccess(): void
    {
        $this->tokenRepo->method('findByToken')->willReturn([
            'id' => 1,
            'user_id' => 1,
            'token' => 'valid-token',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $this->userRepo->method('findById')->willReturn([
            'id' => 1,
            'email' => 'test@test.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $result = $this->service->refresh(['refresh_token' => 'valid-token']);

        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertNotSame('valid-token', $result['refresh_token']);
    }

    // ── Logout ───────────────────────────────────────────────────

    public function testLogoutDeletesAllTokens(): void
    {
        $this->tokenRepo->expects($this->once())
            ->method('deleteAllByUserId')
            ->with(42);

        $this->service->logout(42);
    }

    // ── Profile ──────────────────────────────────────────────────

    public function testGetProfileReturnsUser(): void
    {
        $this->userRepo->method('findById')->willReturn([
            'id' => 1,
            'email' => 'test@test.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $result = $this->service->getProfile(1);

        $this->assertSame('test@test.com', $result['email']);
    }

    public function testGetProfileThrowsWhenNotFound(): void
    {
        $this->userRepo->method('findById')->willReturn(null);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('auth.error.token_invalid');

        $this->service->getProfile(999);
    }

    // ── Token generation ─────────────────────────────────────────

    public function testGenerateAccessTokenIsValidJwt(): void
    {
        $this->userRepo->method('existsByEmail')->willReturn(false);
        $this->userRepo->method('create')->willReturn([
            'id' => 1,
            'email' => 'jwt@test.com',
        ]);

        $result = $this->service->register(['email' => 'jwt@test.com', 'password' => 'Test1234']);

        // Decode the JWT to verify structure
        $parts = explode('.', $result['access_token']);
        $this->assertCount(3, $parts);

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $this->assertSame(1, $payload['sub']);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertArrayHasKey('iat', $payload);
    }

    public function testRefreshTokenIsOpaque(): void
    {
        $this->userRepo->method('existsByEmail')->willReturn(false);
        $this->userRepo->method('create')->willReturn([
            'id' => 1,
            'email' => 'tok@test.com',
        ]);

        $result = $this->service->register(['email' => 'tok@test.com', 'password' => 'Test1234']);

        // Refresh token should be a hex string (64 chars = 32 bytes)
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['refresh_token']);
    }

    // ── Bcrypt cost ─────────────────────────────────────────────

    public function testRegisterUsesBcryptCostFromConfig(): void
    {
        $capturedPassword = null;
        $this->userRepo->method('existsByEmail')->willReturn(false);
        $this->userRepo->method('create')->willReturnCallback(function (array $data) use (&$capturedPassword) {
            $capturedPassword = $data['password'];
            return ['id' => 1, 'email' => $data['email']];
        });

        $this->service->register(['email' => 'cost@test.com', 'password' => 'Test1234']);

        $this->assertNotNull($capturedPassword);
        $info = password_get_info($capturedPassword);
        $this->assertSame('bcrypt', $info['algoName']);
        $this->assertSame(12, $info['options']['cost']);
    }

    // ── Length validations ───────────────────────────────────────

    public function testRegisterThrowsWhenEmailTooLong(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.email_too_long');

        $longEmail = str_repeat('a', 247) . '@test.com'; // 256 chars
        $this->service->register(['email' => $longEmail, 'password' => 'Test1234']);
    }

    public function testRegisterThrowsWhenFirstNameTooLong(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.field_too_long');

        $this->service->register([
            'email' => 'long@test.com',
            'password' => 'Test1234',
            'first_name' => str_repeat('a', 101),
        ]);
    }

    public function testRegisterThrowsWhenLastNameTooLong(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.field_too_long');

        $this->service->register([
            'email' => 'long@test.com',
            'password' => 'Test1234',
            'last_name' => str_repeat('a', 101),
        ]);
    }

    public function testRegisterThrowsWhenPasswordTooLong(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.password_too_long');

        $longPassword = 'Aa1' . str_repeat('x', 70); // 73 bytes > 72
        $this->service->register(['email' => 'long@test.com', 'password' => $longPassword]);
    }
}
