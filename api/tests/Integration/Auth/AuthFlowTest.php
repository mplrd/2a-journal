<?php

namespace Tests\Integration\Auth;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class AuthFlowTest extends TestCase
{
    private Router $router;
    private PDO $pdo;

    protected function setUp(): void
    {
        // Load .env
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

        // Clean tables
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');

        // Build router with routes
        $router = new Router();
        require __DIR__ . '/../../../config/routes.php';
        $this->router = $router;
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    private function extractRefreshToken(Response $response): string
    {
        $cookie = $response->getHeader('Set-Cookie');
        $this->assertNotNull($cookie, 'Expected Set-Cookie header');
        preg_match('/refresh_token=([^;]+)/', $cookie, $matches);
        $this->assertNotEmpty($matches[1], 'Expected refresh token value in cookie');
        return $matches[1];
    }

    // ── Register ─────────────────────────────────────────────────

    public function testRegisterSuccess(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'new@test.com',
            'password' => 'Test1234',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('access_token', $body['data']);
        $this->assertArrayNotHasKey('refresh_token', $body['data']);
        $this->assertSame('new@test.com', $body['data']['user']['email']);

        // Refresh token is in Set-Cookie header
        $cookie = $response->getHeader('Set-Cookie');
        $this->assertNotNull($cookie);
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('Path=/api/auth', $cookie);
    }

    public function testRegisterMissingEmail(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'password' => 'Test1234',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('VALIDATION_ERROR', $e->getErrorCode());
            $this->assertSame('email', $e->getField());
        }
    }

    public function testRegisterInvalidEmail(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'not-valid',
            'password' => 'Test1234',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('auth.error.invalid_email', $e->getMessageKey());
        }
    }

    public function testRegisterWeakPassword(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'weak@test.com',
            'password' => 'weak',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('auth.error.password_too_weak', $e->getMessageKey());
        }
    }

    public function testRegisterDuplicateEmail(): void
    {
        // First register
        $request = Request::create('POST', '/auth/register', [
            'email' => 'dup@test.com',
            'password' => 'Test1234',
        ]);
        $this->router->dispatch($request);

        // Second register with same email
        $request = Request::create('POST', '/auth/register', [
            'email' => 'dup@test.com',
            'password' => 'Test1234',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('EMAIL_TAKEN', $e->getErrorCode());
        }
    }

    // ── Login ────────────────────────────────────────────────────

    public function testLoginSuccess(): void
    {
        // Register first
        $request = Request::create('POST', '/auth/register', [
            'email' => 'login@test.com',
            'password' => 'Test1234',
        ]);
        $this->router->dispatch($request);

        // Login
        $request = Request::create('POST', '/auth/login', [
            'email' => 'login@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('access_token', $body['data']);
        $this->assertArrayNotHasKey('refresh_token', $body['data']);
        $this->assertSame('login@test.com', $body['data']['user']['email']);

        // Refresh token in cookie
        $this->assertNotNull($response->getHeader('Set-Cookie'));
    }

    public function testLoginWrongPassword(): void
    {
        // Register first
        $request = Request::create('POST', '/auth/register', [
            'email' => 'wrong@test.com',
            'password' => 'Test1234',
        ]);
        $this->router->dispatch($request);

        // Login with wrong password
        $request = Request::create('POST', '/auth/login', [
            'email' => 'wrong@test.com',
            'password' => 'Wrong123',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('INVALID_CREDENTIALS', $e->getErrorCode());
        }
    }

    public function testLoginUnknownEmail(): void
    {
        $request = Request::create('POST', '/auth/login', [
            'email' => 'nobody@test.com',
            'password' => 'Test1234',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('INVALID_CREDENTIALS', $e->getErrorCode());
        }
    }

    public function testLoginMissingFields(): void
    {
        $request = Request::create('POST', '/auth/login', []);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    // ── Refresh ──────────────────────────────────────────────────

    public function testRefreshSuccessViaCookie(): void
    {
        // Register to get tokens
        $request = Request::create('POST', '/auth/register', [
            'email' => 'refresh@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $refreshToken = $this->extractRefreshToken($response);

        // Refresh via cookie
        $request = Request::create('POST', '/auth/refresh', [], [], [], ['refresh_token' => $refreshToken]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('access_token', $body['data']);
        $this->assertArrayNotHasKey('refresh_token', $body['data']);

        // New cookie set with rotated token
        $newToken = $this->extractRefreshToken($response);
        $this->assertNotSame($refreshToken, $newToken);
    }

    public function testRefreshSuccessViaBodyFallback(): void
    {
        // Register to get tokens
        $request = Request::create('POST', '/auth/register', [
            'email' => 'refreshbody@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $refreshToken = $this->extractRefreshToken($response);

        // Refresh via body (fallback)
        $request = Request::create('POST', '/auth/refresh', ['refresh_token' => $refreshToken]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('access_token', $body['data']);
    }

    public function testRefreshInvalidToken(): void
    {
        $request = Request::create('POST', '/auth/refresh', ['refresh_token' => 'bad-token']);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('REFRESH_TOKEN_INVALID', $e->getErrorCode());
        }
    }

    public function testRefreshMissingToken(): void
    {
        $request = Request::create('POST', '/auth/refresh', []);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testOldRefreshTokenInvalidAfterRotation(): void
    {
        // Register
        $request = Request::create('POST', '/auth/register', [
            'email' => 'rotate@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $oldToken = $this->extractRefreshToken($response);

        // Refresh to get new token
        $request = Request::create('POST', '/auth/refresh', [], [], [], ['refresh_token' => $oldToken]);
        $this->router->dispatch($request);

        // Try old token again -> should fail
        $request = Request::create('POST', '/auth/refresh', [], [], [], ['refresh_token' => $oldToken]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── Me (profile) ─────────────────────────────────────────────

    public function testMeSuccess(): void
    {
        // Register to get access token
        $request = Request::create('POST', '/auth/register', [
            'email' => 'me@test.com',
            'password' => 'Test1234',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);
        $response = $this->router->dispatch($request);
        $accessToken = $response->getBody()['data']['access_token'];

        // Get profile
        $request = Request::create('GET', '/auth/me', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('me@test.com', $body['data']['email']);
        $this->assertSame('Jane', $body['data']['first_name']);
        $this->assertSame('Smith', $body['data']['last_name']);
    }

    public function testMeWithoutToken(): void
    {
        $request = Request::create('GET', '/auth/me');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('TOKEN_MISSING', $e->getErrorCode());
        }
    }

    public function testMeWithInvalidToken(): void
    {
        $request = Request::create('GET', '/auth/me', [], [], [
            'Authorization' => 'Bearer invalid.token.here',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── Logout ───────────────────────────────────────────────────

    public function testLogoutSuccess(): void
    {
        // Register
        $request = Request::create('POST', '/auth/register', [
            'email' => 'logout@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $data = $response->getBody()['data'];
        $refreshToken = $this->extractRefreshToken($response);

        // Logout
        $request = Request::create('POST', '/auth/logout', [], [], [
            'Authorization' => "Bearer {$data['access_token']}",
        ]);
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getBody()['success']);

        // Clear cookie should be set
        $cookie = $response->getHeader('Set-Cookie');
        $this->assertNotNull($cookie);
        $this->assertStringContainsString('Max-Age=0', $cookie);

        // Refresh token should no longer work
        $request = Request::create('POST', '/auth/refresh', [], [], [], ['refresh_token' => $refreshToken]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testLogoutWithoutToken(): void
    {
        $request = Request::create('POST', '/auth/logout');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── Update locale ─────────────────────────────────────────────

    public function testRegisterWithLocale(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'localed@test.com',
            'password' => 'Test1234',
            'locale' => 'en',
        ]);
        $response = $this->router->dispatch($request);
        $accessToken = $response->getBody()['data']['access_token'];

        $request = Request::create('GET', '/auth/me', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $response = $this->router->dispatch($request);
        $this->assertSame('en', $response->getBody()['data']['locale']);
    }

    public function testRegisterWithInvalidLocaleFallsBackToEn(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'badlang@test.com',
            'password' => 'Test1234',
            'locale' => 'de',
        ]);
        $response = $this->router->dispatch($request);
        $accessToken = $response->getBody()['data']['access_token'];

        $request = Request::create('GET', '/auth/me', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $response = $this->router->dispatch($request);
        $this->assertSame('en', $response->getBody()['data']['locale']);
    }

    public function testRegisterWithoutLocaleFallsBackToEn(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'nolang@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $accessToken = $response->getBody()['data']['access_token'];

        $request = Request::create('GET', '/auth/me', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $response = $this->router->dispatch($request);
        $this->assertSame('en', $response->getBody()['data']['locale']);
    }

    public function testUpdateLocaleSuccess(): void
    {
        // Register with default locale
        $request = Request::create('POST', '/auth/register', [
            'email' => 'locale@test.com',
            'password' => 'Test1234',
            'locale' => 'fr',
        ]);
        $response = $this->router->dispatch($request);
        $accessToken = $response->getBody()['data']['access_token'];

        // Verify initial locale
        $request = Request::create('GET', '/auth/me', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $response = $this->router->dispatch($request);
        $this->assertSame('fr', $response->getBody()['data']['locale']);

        // Update locale to 'en'
        $request = Request::create('PATCH', '/auth/locale', ['locale' => 'en'], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('en', $body['data']['locale']);

        // Verify persistence via /me
        $request = Request::create('GET', '/auth/me', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $response = $this->router->dispatch($request);
        $this->assertSame('en', $response->getBody()['data']['locale']);
    }

    public function testUpdateLocaleInvalidValue(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'badlocale@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $accessToken = $response->getBody()['data']['access_token'];

        $request = Request::create('PATCH', '/auth/locale', ['locale' => 'de'], [], [
            'Authorization' => "Bearer $accessToken",
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('auth.error.invalid_locale', $e->getMessageKey());
        }
    }

    public function testUpdateLocaleWithoutAuth(): void
    {
        $request = Request::create('PATCH', '/auth/locale', ['locale' => 'en']);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── Update profile ──────────────────────────────────────────────

    public function testUpdateProfileSuccess(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'profile@test.com',
            'password' => 'Test1234',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        $response = $this->router->dispatch($request);
        $accessToken = $response->getBody()['data']['access_token'];

        $request = Request::create('PATCH', '/auth/profile', [
            'first_name' => 'Jane',
            'theme' => 'dark',
            'locale' => 'fr',
        ], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('Jane', $body['data']['first_name']);
        $this->assertSame('dark', $body['data']['theme']);
        $this->assertSame('fr', $body['data']['locale']);
    }

    public function testUpdateProfilePersists(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'persist@test.com',
            'password' => 'Test1234',
            'first_name' => 'John',
        ]);
        $response = $this->router->dispatch($request);
        $accessToken = $response->getBody()['data']['access_token'];

        $request = Request::create('PATCH', '/auth/profile', [
            'first_name' => 'Updated',
            'theme' => 'dark',
        ], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $this->router->dispatch($request);

        $request = Request::create('GET', '/auth/me', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame('Updated', $body['data']['first_name']);
        $this->assertSame('dark', $body['data']['theme']);
    }

    public function testUpdateProfilePartialUpdate(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'partial@test.com',
            'password' => 'Test1234',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'locale' => 'fr',
        ]);
        $response = $this->router->dispatch($request);
        $accessToken = $response->getBody()['data']['access_token'];

        // Only update theme
        $request = Request::create('PATCH', '/auth/profile', [
            'theme' => 'dark',
        ], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame('dark', $body['data']['theme']);
        $this->assertSame('John', $body['data']['first_name']);
        $this->assertSame('Doe', $body['data']['last_name']);
        $this->assertSame('fr', $body['data']['locale']);
    }

    public function testUpdateProfileInvalidTheme(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'badtheme@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $accessToken = $response->getBody()['data']['access_token'];

        $request = Request::create('PATCH', '/auth/profile', [
            'theme' => 'blue',
        ], [], [
            'Authorization' => "Bearer $accessToken",
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('auth.error.invalid_theme', $e->getMessageKey());
        }
    }

    public function testUpdateProfileInvalidTimezone(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'badtz@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $accessToken = $response->getBody()['data']['access_token'];

        $request = Request::create('PATCH', '/auth/profile', [
            'timezone' => 'Fake/Zone',
        ], [], [
            'Authorization' => "Bearer $accessToken",
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('auth.error.invalid_timezone', $e->getMessageKey());
        }
    }

    public function testUpdateProfileInvalidCurrency(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'badcur@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $accessToken = $response->getBody()['data']['access_token'];

        $request = Request::create('PATCH', '/auth/profile', [
            'default_currency' => 'ABCD',
        ], [], [
            'Authorization' => "Bearer $accessToken",
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('auth.error.invalid_currency', $e->getMessageKey());
        }
    }

    public function testUpdateProfileWithoutAuth(): void
    {
        $request = Request::create('PATCH', '/auth/profile', ['theme' => 'dark']);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── Profile picture upload ──────────────────────────────────────

    private function createTempImage(string $mimeType = 'image/jpeg', int $size = 1024): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_img_');
        if ($mimeType === 'image/jpeg') {
            $img = imagecreatetruecolor(100, 100);
            imagejpeg($img, $tmpFile);
            imagedestroy($img);
        } elseif ($mimeType === 'image/png') {
            $img = imagecreatetruecolor(100, 100);
            imagepng($img, $tmpFile);
            imagedestroy($img);
        } else {
            // Write plain text for invalid type tests
            file_put_contents($tmpFile, str_repeat('x', $size));
        }
        return $tmpFile;
    }

    private function registerAndGetToken(string $email = 'avatar@test.com'): string
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => $email,
            'password' => 'Test1234',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        $response = $this->router->dispatch($request);
        return $response->getBody()['data']['access_token'];
    }

    public function testUploadProfilePictureSuccess(): void
    {
        $accessToken = $this->registerAndGetToken('avatar-ok@test.com');
        $tmpFile = $this->createTempImage('image/jpeg');

        $request = Request::create('POST', '/auth/profile-picture', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $request->setFiles([
            'profile_picture' => [
                'name' => 'photo.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
            ],
        ]);

        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertNotNull($body['data']['profile_picture']);
        $this->assertStringContainsString('uploads/avatars/', $body['data']['profile_picture']);

        // Cleanup
        $picturePath = __DIR__ . '/../../../public/' . $body['data']['profile_picture'];
        if (file_exists($picturePath)) {
            unlink($picturePath);
        }
        @unlink($tmpFile);
    }

    public function testUploadProfilePictureInvalidType(): void
    {
        $accessToken = $this->registerAndGetToken('avatar-badtype@test.com');
        $tmpFile = $this->createTempImage('text/plain', 100);

        $request = Request::create('POST', '/auth/profile-picture', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $request->setFiles([
            'profile_picture' => [
                'name' => 'file.txt',
                'type' => 'text/plain',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
            ],
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('auth.error.invalid_image_type', $e->getMessageKey());
        }

        @unlink($tmpFile);
    }

    public function testUploadProfilePictureTooLarge(): void
    {
        $accessToken = $this->registerAndGetToken('avatar-big@test.com');
        $tmpFile = $this->createTempImage('image/jpeg');
        // Simulate oversized file by setting size > 2MB in the file array
        $request = Request::create('POST', '/auth/profile-picture', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $request->setFiles([
            'profile_picture' => [
                'name' => 'big.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => 3 * 1024 * 1024, // 3MB
            ],
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('auth.error.image_too_large', $e->getMessageKey());
        }

        @unlink($tmpFile);
    }

    public function testUploadProfilePictureWithoutAuth(): void
    {
        $request = Request::create('POST', '/auth/profile-picture');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── Onboarding ──────────────────────────────────────────────────

    public function testRegisterReturnsOnboardingNull(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'onboard@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertArrayHasKey('onboarding_completed_at', $body['data']['user']);
        $this->assertNull($body['data']['user']['onboarding_completed_at']);
    }

    public function testCompleteOnboardingSuccess(): void
    {
        $accessToken = $this->registerAndGetToken('onboard-complete@test.com');

        $request = Request::create('POST', '/auth/complete-onboarding', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertNotNull($body['data']['onboarding_completed_at']);
    }

    public function testCompleteOnboardingIdempotent(): void
    {
        $accessToken = $this->registerAndGetToken('onboard-idem@test.com');

        // First call
        $request = Request::create('POST', '/auth/complete-onboarding', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $response = $this->router->dispatch($request);
        $firstTimestamp = $response->getBody()['data']['onboarding_completed_at'];

        // Second call should not change timestamp
        $request = Request::create('POST', '/auth/complete-onboarding', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $response = $this->router->dispatch($request);
        $secondTimestamp = $response->getBody()['data']['onboarding_completed_at'];

        $this->assertSame($firstTimestamp, $secondTimestamp);
    }

    public function testCompleteOnboardingWithoutAuth(): void
    {
        $request = Request::create('POST', '/auth/complete-onboarding');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testOnboardingCompletedAtInMe(): void
    {
        $accessToken = $this->registerAndGetToken('onboard-me@test.com');

        $request = Request::create('GET', '/auth/me', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('onboarding_completed_at', $body['data']);
    }

    public function testProfilePictureUrlInMe(): void
    {
        $accessToken = $this->registerAndGetToken('avatar-me@test.com');
        $tmpFile = $this->createTempImage('image/jpeg');

        // Upload picture
        $request = Request::create('POST', '/auth/profile-picture', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $request->setFiles([
            'profile_picture' => [
                'name' => 'photo.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
            ],
        ]);
        $uploadResponse = $this->router->dispatch($request);
        $picturePath = $uploadResponse->getBody()['data']['profile_picture'];

        // GET /auth/me should return profile_picture
        $request = Request::create('GET', '/auth/me', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($picturePath, $body['data']['profile_picture']);

        // Cleanup
        $fullPath = __DIR__ . '/../../../public/' . $picturePath;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
        @unlink($tmpFile);
    }

    // ── Change password ──────────────────────────────────────────

    public function testChangePasswordRequiresAuth(): void
    {
        $request = Request::create('POST', '/auth/change-password', [
            'current_password' => 'Test1234',
            'new_password' => 'NewPass1',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testChangePasswordRejectsWrongCurrent(): void
    {
        $accessToken = $this->registerAndGetToken('changepwd-wrong@test.com');

        $request = Request::create('POST', '/auth/change-password', [
            'current_password' => 'Wrong999',
            'new_password' => 'NewPass1',
        ], [], ['Authorization' => "Bearer $accessToken"]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('auth.error.invalid_current_password', $e->getMessageKey());
        }
    }

    public function testChangePasswordRejectsWeakNew(): void
    {
        $accessToken = $this->registerAndGetToken('changepwd-weak@test.com');

        $request = Request::create('POST', '/auth/change-password', [
            'current_password' => 'Test1234',
            'new_password' => 'weak',
        ], [], ['Authorization' => "Bearer $accessToken"]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('auth.error.password_too_weak', $e->getMessageKey());
        }
    }

    public function testChangePasswordSuccessAndLoginWithNew(): void
    {
        $accessToken = $this->registerAndGetToken('changepwd-ok@test.com');

        $request = Request::create('POST', '/auth/change-password', [
            'current_password' => 'Test1234',
            'new_password' => 'BrandNew1',
        ], [], ['Authorization' => "Bearer $accessToken"]);
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());

        // Login with new password works
        $request = Request::create('POST', '/auth/login', [
            'email' => 'changepwd-ok@test.com',
            'password' => 'BrandNew1',
        ]);
        $response = $this->router->dispatch($request);
        $this->assertSame(200, $response->getStatusCode());

        // Login with old password fails
        $request = Request::create('POST', '/auth/login', [
            'email' => 'changepwd-ok@test.com',
            'password' => 'Test1234',
        ]);
        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── Delete account ───────────────────────────────────────────

    public function testDeleteAccountRequiresAuth(): void
    {
        $request = Request::create('DELETE', '/auth/me', [
            'password' => 'Test1234',
            'email_confirmation' => 'somebody@test.com',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testDeleteAccountRejectsEmailMismatch(): void
    {
        $accessToken = $this->registerAndGetToken('del-mismatch@test.com');

        $request = Request::create('DELETE', '/auth/me', [
            'password' => 'Test1234',
            'email_confirmation' => 'wrong@test.com',
        ], [], ['Authorization' => "Bearer $accessToken"]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('auth.error.email_confirmation_mismatch', $e->getMessageKey());
        }
    }

    public function testDeleteAccountRejectsWrongPassword(): void
    {
        $accessToken = $this->registerAndGetToken('del-pwd@test.com');

        $request = Request::create('DELETE', '/auth/me', [
            'password' => 'Wrong999',
            'email_confirmation' => 'del-pwd@test.com',
        ], [], ['Authorization' => "Bearer $accessToken"]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testDeleteAccountSuccessThenLoginFails(): void
    {
        $accessToken = $this->registerAndGetToken('del-ok@test.com');

        $request = Request::create('DELETE', '/auth/me', [
            'password' => 'Test1234',
            'email_confirmation' => 'del-ok@test.com',
        ], [], ['Authorization' => "Bearer $accessToken"]);
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $cookie = $response->getHeader('Set-Cookie');
        $this->assertNotNull($cookie);
        $this->assertStringContainsString('Max-Age=0', $cookie);

        // Deleted user can no longer log in
        $request = Request::create('POST', '/auth/login', [
            'email' => 'del-ok@test.com',
            'password' => 'Test1234',
        ]);
        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }

        // Row still exists in DB (soft delete)
        $stmt = $this->pdo->prepare('SELECT deleted_at FROM users WHERE email = :e');
        $stmt->execute(['e' => 'del-ok@test.com']);
        $row = $stmt->fetch();
        $this->assertNotFalse($row, 'User row should still exist after soft delete');
        $this->assertNotNull($row['deleted_at']);
    }
}
