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
            'cookie_name' => 'refresh_token',
            'cookie_path' => '/api/auth',
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => false,
        ];

        $this->userRepo = $this->createMock(UserRepository::class);
        $this->tokenRepo = $this->createMock(RefreshTokenRepository::class);

        $this->service = new AuthService($this->userRepo, $this->tokenRepo, null, null, $this->config);
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
        $userData = [
            'id' => 1,
            'email' => 'new@test.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email_verified' => true,
            'email_verified_at' => '2026-01-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
        $this->userRepo->method('existsByEmail')->willReturn(false);
        $this->userRepo->method('create')->willReturn($userData);
        $this->userRepo->method('findById')->willReturn($userData);

        $result = $this->service->register([
            'email' => 'new@test.com',
            'password' => 'Test1234',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_cookie', $result);
        $this->assertArrayNotHasKey('refresh_token', $result);
        $this->assertArrayHasKey('user', $result);
        $this->assertSame('new@test.com', $result['user']['email']);
        $this->assertStringContainsString('refresh_token=', $result['refresh_cookie']);
        $this->assertStringContainsString('HttpOnly', $result['refresh_cookie']);
        $this->assertStringContainsString('Path=/api/auth', $result['refresh_cookie']);
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
            'locked_until' => null,
            'failed_login_attempts' => 0,
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
            'locked_until' => null,
            'failed_login_attempts' => 0,
        ]);
        $this->userRepo->method('findById')->willReturn([
            'id' => 1,
            'email' => 'test@test.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $result = $this->service->login(['email' => 'test@test.com', 'password' => 'Test1234']);

        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_cookie', $result);
        $this->assertArrayNotHasKey('refresh_token', $result);
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
        $this->assertArrayHasKey('refresh_cookie', $result);
        $this->assertArrayNotHasKey('refresh_token', $result);
        $this->assertStringContainsString('refresh_token=', $result['refresh_cookie']);
        $this->assertStringNotContainsString('refresh_token=valid-token', $result['refresh_cookie']);
    }

    // ── Logout ───────────────────────────────────────────────────

    public function testLogoutDeletesAllTokensAndReturnsClearCookie(): void
    {
        $this->tokenRepo->expects($this->once())
            ->method('deleteAllByUserId')
            ->with(42);

        $result = $this->service->logout(42);

        $this->assertArrayHasKey('refresh_cookie', $result);
        $this->assertStringContainsString('refresh_token=;', $result['refresh_cookie']);
        $this->assertStringContainsString('Max-Age=0', $result['refresh_cookie']);
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

    public function testUpdateProfileAcceptsValidBeThresholdPercent(): void
    {
        $this->userRepo
            ->expects($this->once())
            ->method('updateProfile')
            ->with(1, ['be_threshold_percent' => 0.05])
            ->willReturn(['id' => 1, 'be_threshold_percent' => 0.05]);

        $result = $this->service->updateProfile(1, ['be_threshold_percent' => 0.05]);

        $this->assertEquals(0.05, (float) $result['be_threshold_percent']);
    }

    public function testUpdateProfileAcceptsZeroBeThresholdPercent(): void
    {
        $this->userRepo
            ->expects($this->once())
            ->method('updateProfile')
            ->with(1, ['be_threshold_percent' => 0])
            ->willReturn(['id' => 1, 'be_threshold_percent' => 0]);

        $this->service->updateProfile(1, ['be_threshold_percent' => 0]);
    }

    public function testUpdateProfileRejectsNegativeBeThresholdPercent(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.invalid_be_threshold');

        $this->service->updateProfile(1, ['be_threshold_percent' => -0.01]);
    }

    public function testUpdateProfileRejectsBeThresholdPercentAboveMax(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.invalid_be_threshold');

        $this->service->updateProfile(1, ['be_threshold_percent' => 5.01]);
    }

    public function testUpdateProfileRejectsNonNumericBeThresholdPercent(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.invalid_be_threshold');

        $this->service->updateProfile(1, ['be_threshold_percent' => 'abc']);
    }

    public function testUpdateProfileAcceptsSupportedPageSizes(): void
    {
        foreach ([10, 25, 50, 100] as $size) {
            $repo = $this->createMock(UserRepository::class);
            $repo->expects($this->once())
                ->method('updateProfile')
                ->with(1, ['default_page_size' => $size])
                ->willReturn(['id' => 1, 'default_page_size' => $size]);

            $service = new AuthService($repo, $this->tokenRepo, null, null, $this->config);
            $result = $service->updateProfile(1, ['default_page_size' => $size]);

            $this->assertSame($size, (int) $result['default_page_size']);
        }
    }

    public function testUpdateProfileRejectsUnsupportedPageSize(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.invalid_page_size');

        $this->service->updateProfile(1, ['default_page_size' => 7]);
    }

    public function testUpdateProfileRejectsNonIntegerPageSize(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.invalid_page_size');

        $this->service->updateProfile(1, ['default_page_size' => 'abc']);
    }

    // ── Change password ──────────────────────────────────────────

    public function testChangePasswordThrowsWhenCurrentMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.field_required');

        $this->service->changePassword(1, ['new_password' => 'NewPass1']);
    }

    public function testChangePasswordThrowsWhenNewMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.field_required');

        $this->service->changePassword(1, ['current_password' => 'OldPass1']);
    }

    public function testChangePasswordThrowsWhenNewTooWeak(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.password_too_weak');

        $this->service->changePassword(1, [
            'current_password' => 'OldPass1',
            'new_password' => 'weak',
        ]);
    }

    public function testChangePasswordThrowsWhenCurrentWrong(): void
    {
        $this->userRepo->method('findById')->willReturn(['id' => 1, 'email' => 'u@test.com']);
        $this->userRepo->method('findByEmail')->willReturn([
            'id' => 1,
            'email' => 'u@test.com',
            'password' => password_hash('Correct1', PASSWORD_BCRYPT),
        ]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('auth.error.invalid_current_password');

        $this->service->changePassword(1, [
            'current_password' => 'Wrong123',
            'new_password' => 'NewPass1',
        ]);
    }

    public function testChangePasswordSuccessKeepsRefreshTokens(): void
    {
        $this->userRepo->method('findById')->willReturn(['id' => 1, 'email' => 'u@test.com']);
        $this->userRepo->method('findByEmail')->willReturn([
            'id' => 1,
            'email' => 'u@test.com',
            'password' => password_hash('Correct1', PASSWORD_BCRYPT),
        ]);

        $this->userRepo->expects($this->once())->method('updatePassword')->with(1, $this->isType('string'));
        // Must NOT revoke refresh tokens (user just proved their identity with current password).
        $this->tokenRepo->expects($this->never())->method('deleteAllByUserId');

        $this->service->changePassword(1, [
            'current_password' => 'Correct1',
            'new_password' => 'NewPass1',
        ]);
    }

    // ── Delete account ───────────────────────────────────────────

    public function testDeleteAccountThrowsWhenEmailMismatch(): void
    {
        $this->userRepo->method('findById')->willReturn(['id' => 1, 'email' => 'u@test.com']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.email_confirmation_mismatch');

        $this->service->deleteAccount(1, [
            'password' => 'Pass1234',
            'email_confirmation' => 'wrong@test.com',
        ]);
    }

    public function testDeleteAccountThrowsWhenPasswordWrong(): void
    {
        $this->userRepo->method('findById')->willReturn(['id' => 1, 'email' => 'u@test.com']);
        $this->userRepo->method('findByEmail')->willReturn([
            'id' => 1,
            'email' => 'u@test.com',
            'password' => password_hash('Correct1', PASSWORD_BCRYPT),
        ]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('auth.error.invalid_credentials');

        $this->service->deleteAccount(1, [
            'password' => 'Wrong123',
            'email_confirmation' => 'u@test.com',
        ]);
    }

    public function testDeleteAccountSoftDeletesAndRevokesTokens(): void
    {
        $this->userRepo->method('findById')->willReturn(['id' => 1, 'email' => 'u@test.com']);
        $this->userRepo->method('findByEmail')->willReturn([
            'id' => 1,
            'email' => 'u@test.com',
            'password' => password_hash('Correct1', PASSWORD_BCRYPT),
        ]);

        $this->userRepo->expects($this->once())->method('softDelete')->with(1);
        $this->tokenRepo->expects($this->once())->method('deleteAllByUserId')->with(1);

        $result = $this->service->deleteAccount(1, [
            'password' => 'Correct1',
            'email_confirmation' => 'u@test.com',
        ]);

        $this->assertArrayHasKey('refresh_cookie', $result);
        $this->assertStringContainsString('Max-Age=0', $result['refresh_cookie']);
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

    public function testRefreshTokenInCookieIsOpaque(): void
    {
        $this->userRepo->method('existsByEmail')->willReturn(false);
        $this->userRepo->method('create')->willReturn([
            'id' => 1,
            'email' => 'tok@test.com',
        ]);

        $result = $this->service->register(['email' => 'tok@test.com', 'password' => 'Test1234']);

        // Extract token value from Set-Cookie string
        preg_match('/refresh_token=([^;]+)/', $result['refresh_cookie'], $matches);
        $this->assertNotEmpty($matches[1]);
        // Refresh token should be a hex string (64 chars = 32 bytes)
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $matches[1]);
    }

    // ── Cookie format ────────────────────────────────────────────

    public function testRefreshCookieContainsSecurityAttributes(): void
    {
        $this->userRepo->method('existsByEmail')->willReturn(false);
        $this->userRepo->method('create')->willReturn([
            'id' => 1,
            'email' => 'cookie@test.com',
        ]);

        $result = $this->service->register(['email' => 'cookie@test.com', 'password' => 'Test1234']);

        $cookie = $result['refresh_cookie'];
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('SameSite=Lax', $cookie);
        $this->assertStringContainsString('Path=/api/auth', $cookie);
        $this->assertStringContainsString('Max-Age=604800', $cookie);
        // Secure flag should NOT be present in dev (cookie_secure=false)
        $this->assertStringNotContainsString('Secure', $cookie);
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

    // ── Update locale ───────────────────────────────────────────

    public function testUpdateLocaleSuccess(): void
    {
        $this->userRepo->method('updateLocale')->willReturn([
            'id' => 1,
            'email' => 'test@test.com',
            'locale' => 'en',
        ]);

        $result = $this->service->updateLocale(1, 'en');

        $this->assertSame('en', $result['locale']);
    }

    public function testUpdateLocaleThrowsWhenEmpty(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.field_required');

        $this->service->updateLocale(1, '');
    }

    public function testUpdateLocaleThrowsWhenInvalid(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('auth.error.invalid_locale');

        $this->service->updateLocale(1, 'de');
    }
}
