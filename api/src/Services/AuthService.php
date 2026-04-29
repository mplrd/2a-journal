<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\ForbiddenException;
use App\Exceptions\HttpException;
use App\Exceptions\UnauthorizedException;
use App\Exceptions\ValidationException;
use App\Repositories\EmailVerificationTokenRepository;
use App\Repositories\PasswordResetTokenRepository;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\SetupRepository;
use App\Repositories\SymbolRepository;
use App\Repositories\UserRepository;
use Firebase\JWT\JWT;

class AuthService
{
    private UserRepository $userRepo;
    private RefreshTokenRepository $tokenRepo;
    private ?SymbolRepository $symbolRepo;
    private ?SetupRepository $setupRepo;
    private ?EmailVerificationTokenRepository $verificationTokenRepo;
    private ?PasswordResetTokenRepository $resetTokenRepo;
    private ?EmailService $emailService;
    private ?PlatformSettingsService $platformSettings;
    private array $config;
    private array $securityConfig;

    public function __construct(
        UserRepository $userRepo,
        RefreshTokenRepository $tokenRepo,
        ?SymbolRepository $symbolRepo,
        ?SetupRepository $setupRepo,
        array $config,
        ?EmailVerificationTokenRepository $verificationTokenRepo = null,
        ?PasswordResetTokenRepository $resetTokenRepo = null,
        ?EmailService $emailService = null,
        array $securityConfig = [],
        ?PlatformSettingsService $platformSettings = null
    ) {
        $this->userRepo = $userRepo;
        $this->tokenRepo = $tokenRepo;
        $this->symbolRepo = $symbolRepo;
        $this->setupRepo = $setupRepo;
        $this->config = $config;
        $this->verificationTokenRepo = $verificationTokenRepo;
        $this->resetTokenRepo = $resetTokenRepo;
        $this->emailService = $emailService;
        $this->securityConfig = $securityConfig;
        $this->platformSettings = $platformSettings;
    }

    /**
     * Resolve a setting with the priority chain DB > env (via config) > default.
     * Wrapped in a helper so each consumer reads the same way and tests can
     * override the platform settings without re-wiring the whole config array.
     */
    private function setting(string $key, string $configKey, mixed $default): mixed
    {
        if ($this->platformSettings !== null) {
            $value = $this->platformSettings->resolve($key);
            if ($value !== null) {
                return $value;
            }
        }
        return $this->config[$configKey] ?? $default;
    }

    public function register(array $data): array
    {
        $this->validateRegisterData($data);

        if ($this->userRepo->existsByEmail($data['email'])) {
            throw new HttpException('EMAIL_TAKEN', 'auth.error.email_taken', 'email', 409);
        }

        $locale = isset($data['locale']) && in_array($data['locale'], self::SUPPORTED_LOCALES, true)
            ? $data['locale']
            : 'en';

        $user = $this->userRepo->create([
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => $this->config['bcrypt_cost']]),
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'locale' => $locale,
        ]);

        $userId = (int)$user['id'];

        // Give the new user a billing grace period. Resolution: DB > env > default.
        $graceDays = (int) $this->setting('billing_grace_days', 'billing_grace_days', 14);
        $graceEnd = date('Y-m-d H:i:s', time() + $graceDays * 86400);
        $this->userRepo->setGracePeriodEnd($userId, $graceEnd);

        // Seed default symbols for new user
        if ($this->symbolRepo) {
            $this->symbolRepo->seedForUser($userId);
        }

        // Seed default setups for new user
        if ($this->setupRepo) {
            $this->setupRepo->seedForUser($userId);
        }

        // Email verification
        if ($this->isEmailVerificationEnabled()) {
            $this->createAndSendVerificationToken($userId, $data['email'], $locale);
        } else {
            // Auto-verify when disabled
            $this->userRepo->setEmailVerified($userId);
            $user = $this->userRepo->findById($userId) ?? $user;
        }

        $accessToken = $this->generateAccessToken($userId);
        $refreshToken = $this->generateRefreshToken($userId);

        return [
            'access_token' => $accessToken,
            'refresh_cookie' => $this->buildRefreshCookie($refreshToken, $this->config['refresh_token_ttl']),
            'user' => $this->enrichUserPayload($user),
        ];
    }

    public function login(array $data): array
    {
        if (empty($data['email'])) {
            throw new ValidationException('auth.error.field_required', 'email');
        }
        if (empty($data['password'])) {
            throw new ValidationException('auth.error.field_required', 'password');
        }

        $user = $this->userRepo->findByEmail($data['email']);

        if (!$user) {
            throw new UnauthorizedException('auth.error.invalid_credentials', 'INVALID_CREDENTIALS');
        }

        // Check account lockout
        $this->checkAccountLockout($user);

        if (!password_verify($data['password'], $user['password'])) {
            $this->handleFailedLogin($user);
            throw new UnauthorizedException('auth.error.invalid_credentials', 'INVALID_CREDENTIALS');
        }

        // After password verification (so suspension status doesn't leak to
        // bruteforce attackers via timing or response codes): refuse suspended
        // users. Returns 403 with a distinct message so the user knows why.
        if (!empty($user['suspended_at'])) {
            throw new ForbiddenException('auth.error.suspended');
        }

        return $this->issueSessionForUser((int) $user['id']);
    }

    /**
     * Issue a fresh session (access_token + refresh cookie + user payload)
     * for a user whose identity has already been verified by an upstream
     * mechanism — e.g. password+email at login time, or a one-time SSO
     * code in the cross-SPA bridge.
     *
     * Still rejects suspended users so the bypass cannot resurrect a
     * disabled account, and refuses unknown user_ids defensively.
     */
    public function issueSessionForUser(int $userId): array
    {
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            throw new UnauthorizedException('auth.error.token_invalid', 'TOKEN_INVALID');
        }

        if (!empty($user['suspended_at'])) {
            throw new ForbiddenException('auth.error.suspended');
        }

        $this->userRepo->resetLoginAttempts($userId);
        $this->userRepo->touchLastLogin($userId);

        $accessToken = $this->generateAccessToken($userId);
        $refreshToken = $this->generateRefreshToken($userId);

        $profile = $this->userRepo->findById($userId);

        return [
            'access_token' => $accessToken,
            'refresh_cookie' => $this->buildRefreshCookie($refreshToken, $this->config['refresh_token_ttl']),
            'user' => $this->enrichUserPayload($profile),
        ];
    }

    public function refresh(array $data): array
    {
        if (empty($data['refresh_token'])) {
            throw new ValidationException('auth.error.field_required', 'refresh_token');
        }

        $stored = $this->tokenRepo->findByToken($data['refresh_token']);

        if (!$stored || strtotime($stored['expires_at']) < time()) {
            if ($stored) {
                $this->tokenRepo->deleteByToken($data['refresh_token']);
            }
            throw new UnauthorizedException('auth.error.refresh_token_invalid', 'REFRESH_TOKEN_INVALID');
        }

        // Rotate: delete old, create new
        $this->tokenRepo->deleteByToken($data['refresh_token']);

        $userId = (int)$stored['user_id'];
        $accessToken = $this->generateAccessToken($userId);
        $refreshToken = $this->generateRefreshToken($userId);

        return [
            'access_token' => $accessToken,
            'refresh_cookie' => $this->buildRefreshCookie($refreshToken, $this->config['refresh_token_ttl']),
        ];
    }

    public function logout(int $userId): array
    {
        $this->tokenRepo->deleteAllByUserId($userId);

        return [
            'refresh_cookie' => $this->buildClearCookie(),
        ];
    }

    public function getProfile(int $userId): array
    {
        $user = $this->userRepo->findById($userId);

        if (!$user) {
            throw new UnauthorizedException('auth.error.token_invalid', 'TOKEN_INVALID');
        }

        return $this->enrichUserPayload($user);
    }

    /**
     * Add fields the SPA needs at session bootstrap (e.g. public_settings for
     * UI feature flags). Used by every endpoint that returns a user payload —
     * login, register, /auth/me — so the SPA never has to hit a second
     * endpoint to know what to render.
     */
    private function enrichUserPayload(array $user): array
    {
        $user['public_settings'] = $this->platformSettings !== null
            ? $this->platformSettings->publicSettings()
            : [];
        return $user;
    }

    public function completeOnboarding(int $userId): array
    {
        $user = $this->userRepo->completeOnboarding($userId);

        if (!$user) {
            throw new UnauthorizedException('auth.error.token_invalid', 'TOKEN_INVALID');
        }

        return $user;
    }

    // ── Email verification ──────────────────────────────────────

    public function verifyEmail(string $token): void
    {
        if (empty($token)) {
            throw new ValidationException('auth.error.field_required', 'token');
        }

        if (!$this->verificationTokenRepo) {
            throw new HttpException('VERIFICATION_ERROR', 'auth.error.invalid_verification_token', null, 400);
        }

        $stored = $this->verificationTokenRepo->findByToken($token);

        if (!$stored) {
            throw new HttpException('INVALID_TOKEN', 'auth.error.invalid_verification_token', null, 400);
        }

        if (strtotime($stored['expires_at']) < time()) {
            $this->verificationTokenRepo->deleteByToken($token);
            throw new HttpException('TOKEN_EXPIRED', 'auth.error.verification_token_expired', null, 400);
        }

        $this->userRepo->setEmailVerified((int)$stored['user_id']);
        $this->verificationTokenRepo->deleteByToken($token);
    }

    public function resendVerification(int $userId): void
    {
        $user = $this->userRepo->findById($userId);

        if (!$user) {
            throw new UnauthorizedException('auth.error.token_invalid', 'TOKEN_INVALID');
        }

        if ($user['email_verified']) {
            throw new HttpException('ALREADY_VERIFIED', 'auth.error.already_verified', null, 400);
        }

        $this->createAndSendVerificationToken($userId, $user['email'], $user['locale'] ?? 'en');
    }

    // ── Password reset ──────────────────────────────────────────

    public function forgotPassword(array $data): void
    {
        if (empty($data['email'])) {
            throw new ValidationException('auth.error.field_required', 'email');
        }

        $user = $this->userRepo->findByEmail($data['email']);

        // Always return success to prevent email enumeration
        if (!$user || !$this->resetTokenRepo) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + ($this->config['reset_token_ttl'] ?? 3600));

        $this->resetTokenRepo->create((int)$user['id'], $token, $expiresAt);

        if ($this->emailService) {
            $this->emailService->sendPasswordResetEmail($user['email'], $token, $user['locale'] ?? 'en');
        }
    }

    public function resetPassword(array $data): void
    {
        if (empty($data['token'])) {
            throw new ValidationException('auth.error.field_required', 'token');
        }
        if (empty($data['password'])) {
            throw new ValidationException('auth.error.field_required', 'password');
        }

        $this->validatePassword($data['password']);

        if (!$this->resetTokenRepo) {
            throw new HttpException('RESET_ERROR', 'auth.error.invalid_reset_token', null, 400);
        }

        $stored = $this->resetTokenRepo->findByToken($data['token']);

        if (!$stored) {
            throw new HttpException('INVALID_TOKEN', 'auth.error.invalid_reset_token', null, 400);
        }

        if (strtotime($stored['expires_at']) < time()) {
            $this->resetTokenRepo->deleteByToken($data['token']);
            throw new HttpException('TOKEN_EXPIRED', 'auth.error.reset_token_expired', null, 400);
        }

        $userId = (int)$stored['user_id'];
        $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => $this->config['bcrypt_cost']]);

        $this->userRepo->updatePassword($userId, $hashedPassword);
        $this->resetTokenRepo->deleteByToken($data['token']);

        // Invalidate all refresh tokens (force re-login)
        $this->tokenRepo->deleteAllByUserId($userId);
    }

    // ── Danger zone: change password & delete account ──────────

    public function changePassword(int $userId, array $data): void
    {
        if (empty($data['current_password'])) {
            throw new ValidationException('auth.error.field_required', 'current_password');
        }
        if (empty($data['new_password'])) {
            throw new ValidationException('auth.error.field_required', 'new_password');
        }

        $this->validatePassword($data['new_password']);

        $user = $this->userRepo->findById($userId);
        if (!$user) {
            throw new UnauthorizedException('auth.error.token_invalid', 'TOKEN_INVALID');
        }

        // findById omits the password hash, fetch it explicitly
        $withHash = $this->userRepo->findByEmail($user['email']);
        if (!$withHash || !password_verify($data['current_password'], $withHash['password'])) {
            throw new UnauthorizedException('auth.error.invalid_current_password', 'INVALID_CURRENT_PASSWORD');
        }

        $hashed = password_hash($data['new_password'], PASSWORD_BCRYPT, ['cost' => $this->config['bcrypt_cost']]);
        $this->userRepo->updatePassword($userId, $hashed);

        // The user just proved their identity with current_password — keep existing sessions active.
    }

    public function deleteAccount(int $userId, array $data): array
    {
        if (empty($data['password'])) {
            throw new ValidationException('auth.error.field_required', 'password');
        }
        if (empty($data['email_confirmation'])) {
            throw new ValidationException('auth.error.field_required', 'email_confirmation');
        }

        $user = $this->userRepo->findById($userId);
        if (!$user) {
            throw new UnauthorizedException('auth.error.token_invalid', 'TOKEN_INVALID');
        }

        if ($data['email_confirmation'] !== $user['email']) {
            throw new ValidationException('auth.error.email_confirmation_mismatch', 'email_confirmation');
        }

        $withHash = $this->userRepo->findByEmail($user['email']);
        if (!$withHash || !password_verify($data['password'], $withHash['password'])) {
            throw new UnauthorizedException('auth.error.invalid_credentials', 'INVALID_CREDENTIALS');
        }

        $this->userRepo->softDelete($userId);
        $this->tokenRepo->deleteAllByUserId($userId);

        return ['refresh_cookie' => $this->buildClearCookie()];
    }

    // ── Profile ─────────────────────────────────────────────────

    private const SUPPORTED_LOCALES = ['fr', 'en'];
    private const SUPPORTED_THEMES = ['light', 'dark'];
    private const SUPPORTED_PAGE_SIZES = [10, 25, 50, 100];
    private const PROFILE_FIELDS = ['first_name', 'last_name', 'timezone', 'default_currency', 'theme', 'locale', 'be_threshold_percent', 'default_page_size'];
    private const BE_THRESHOLD_MAX = 5.0;
    private const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_IMAGE_SIZE = 2 * 1024 * 1024; // 2 MB
    private const IMAGE_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function updateProfile(int $userId, array $data): array
    {
        // Whitelist fields
        $filtered = array_intersect_key($data, array_flip(self::PROFILE_FIELDS));

        // Validate first_name / last_name length
        foreach (['first_name', 'last_name'] as $nameField) {
            if (isset($filtered[$nameField]) && mb_strlen($filtered[$nameField]) > 100) {
                throw new ValidationException('auth.error.field_too_long', $nameField);
            }
        }

        // Validate timezone
        if (isset($filtered['timezone'])) {
            if (!in_array($filtered['timezone'], \DateTimeZone::listIdentifiers(), true)) {
                throw new ValidationException('auth.error.invalid_timezone', 'timezone');
            }
        }

        // Validate currency
        if (isset($filtered['default_currency'])) {
            if (!preg_match('/^[A-Z]{3}$/', $filtered['default_currency'])) {
                throw new ValidationException('auth.error.invalid_currency', 'default_currency');
            }
        }

        // Validate theme
        if (isset($filtered['theme'])) {
            if (!in_array($filtered['theme'], self::SUPPORTED_THEMES, true)) {
                throw new ValidationException('auth.error.invalid_theme', 'theme');
            }
        }

        // Validate locale
        if (isset($filtered['locale'])) {
            if (!in_array($filtered['locale'], self::SUPPORTED_LOCALES, true)) {
                throw new ValidationException('auth.error.invalid_locale', 'locale');
            }
        }

        // Validate default page size (allow-list to keep DB queries bounded)
        if (array_key_exists('default_page_size', $filtered)) {
            $raw = $filtered['default_page_size'];
            if (!is_int($raw) && (!is_string($raw) || !ctype_digit($raw))) {
                throw new ValidationException('auth.error.invalid_page_size', 'default_page_size');
            }
            $size = (int) $raw;
            if (!in_array($size, self::SUPPORTED_PAGE_SIZES, true)) {
                throw new ValidationException('auth.error.invalid_page_size', 'default_page_size');
            }
            $filtered['default_page_size'] = $size;
        }

        // Validate BE threshold (% of entry price, 0 disables the rule)
        if (array_key_exists('be_threshold_percent', $filtered)) {
            $raw = $filtered['be_threshold_percent'];
            if (!is_numeric($raw)) {
                throw new ValidationException('auth.error.invalid_be_threshold', 'be_threshold_percent');
            }
            $value = (float) $raw;
            if ($value < 0 || $value > self::BE_THRESHOLD_MAX) {
                throw new ValidationException('auth.error.invalid_be_threshold', 'be_threshold_percent');
            }
            $filtered['be_threshold_percent'] = $value;
        }

        return $this->userRepo->updateProfile($userId, $filtered);
    }

    public function updateLocale(int $userId, string $locale): array
    {
        if (empty($locale)) {
            throw new ValidationException('auth.error.field_required', 'locale');
        }
        if (!in_array($locale, self::SUPPORTED_LOCALES, true)) {
            throw new ValidationException('auth.error.invalid_locale', 'locale');
        }

        return $this->userRepo->updateLocale($userId, $locale);
    }

    public function uploadProfilePicture(int $userId, array $file): array
    {
        if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException('auth.error.field_required', 'profile_picture');
        }

        // Validate size
        if ($file['size'] > self::MAX_IMAGE_SIZE) {
            throw new ValidationException('auth.error.image_too_large', 'profile_picture');
        }

        // Validate MIME type via finfo (not trusting client-provided type)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, self::ALLOWED_IMAGE_TYPES, true)) {
            throw new ValidationException('auth.error.invalid_image_type', 'profile_picture');
        }

        // Generate unique filename
        $ext = self::IMAGE_EXTENSIONS[$mimeType];
        $filename = "{$userId}_" . time() . ".{$ext}";
        $uploadDir = __DIR__ . '/../../public/uploads/avatars';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $destination = "{$uploadDir}/{$filename}";

        // Delete old picture if exists
        $currentUser = $this->userRepo->findById($userId);
        if ($currentUser && !empty($currentUser['profile_picture'])) {
            $oldPath = __DIR__ . '/../../public/' . $currentUser['profile_picture'];
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }

        // Move uploaded file (or copy for tests)
        if (is_uploaded_file($file['tmp_name'])) {
            move_uploaded_file($file['tmp_name'], $destination);
        } else {
            copy($file['tmp_name'], $destination);
        }

        $relativePath = "uploads/avatars/{$filename}";

        return $this->userRepo->updateProfile($userId, ['profile_picture' => $relativePath]);
    }

    // ── Private helpers ─────────────────────────────────────────

    private function isEmailVerificationEnabled(): bool
    {
        $enabled = (bool) $this->setting('email_verification_enabled', 'email_verification_enabled', true);
        return $enabled && $this->verificationTokenRepo !== null;
    }

    private function createAndSendVerificationToken(int $userId, string $email, string $locale): void
    {
        if (!$this->verificationTokenRepo) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + ($this->config['verification_token_ttl'] ?? 86400));

        $this->verificationTokenRepo->create($userId, $token, $expiresAt);

        if ($this->emailService) {
            $this->emailService->sendVerificationEmail($email, $token, $locale);
        }
    }

    private function checkAccountLockout(array $user): void
    {
        if (!empty($user['locked_until'])) {
            if (strtotime($user['locked_until']) > time()) {
                throw new HttpException('ACCOUNT_LOCKED', 'auth.error.account_locked', null, 423);
            }
            // Lock expired, reset
            $this->userRepo->resetLoginAttempts((int)$user['id']);
        }
    }

    private function handleFailedLogin(array $user): void
    {
        $userId = (int)$user['id'];
        $attempts = $this->userRepo->incrementFailedLoginAttempts($userId);

        $maxAttempts = $this->securityConfig['lockout']['max_attempts'] ?? 5;
        $lockoutSeconds = $this->securityConfig['lockout']['lockout_seconds'] ?? 900;

        if ($attempts >= $maxAttempts) {
            $lockedUntil = date('Y-m-d H:i:s', time() + $lockoutSeconds);
            $this->userRepo->lockAccount($userId, $lockedUntil);

            // Send lockout notification email
            if ($this->emailService) {
                $this->emailService->sendAccountLockedEmail($user['email'], $user['locale'] ?? 'en');
            }
        }
    }

    private function buildRefreshCookie(string $token, int $ttl): string
    {
        $parts = [
            "{$this->config['cookie_name']}=$token",
            "Path={$this->config['cookie_path']}",
            "Max-Age=$ttl",
            "SameSite={$this->config['cookie_samesite']}",
        ];
        if ($this->config['cookie_httponly']) {
            $parts[] = 'HttpOnly';
        }
        if ($this->config['cookie_secure']) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private function buildClearCookie(): string
    {
        $parts = [
            "{$this->config['cookie_name']}=",
            "Path={$this->config['cookie_path']}",
            'Max-Age=0',
            "SameSite={$this->config['cookie_samesite']}",
        ];
        if ($this->config['cookie_httponly']) {
            $parts[] = 'HttpOnly';
        }
        if ($this->config['cookie_secure']) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private function generateAccessToken(int $userId): string
    {
        $now = time();
        // Embed the role claim so the admin frontend and RequireAdminMiddleware
        // can authorize without an extra DB roundtrip per request. A user
        // promoted/demoted between two logins must reconnect to refresh the
        // claim — acceptable for this admin-only feature flag.
        $user = $this->userRepo->findById($userId);
        $payload = [
            'sub' => $userId,
            'role' => $user['role'] ?? UserRole::USER->value,
            'iat' => $now,
            'exp' => $now + $this->config['access_token_ttl'],
        ];

        return JWT::encode($payload, $this->config['jwt_secret'], $this->config['jwt_algo']);
    }

    private function generateRefreshToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + $this->config['refresh_token_ttl']);

        $this->tokenRepo->create($userId, $token, $expiresAt);

        return $token;
    }

    private function validateRegisterData(array $data): void
    {
        if (empty($data['email'])) {
            throw new ValidationException('auth.error.field_required', 'email');
        }
        if (empty($data['password'])) {
            throw new ValidationException('auth.error.field_required', 'password');
        }
        if (mb_strlen($data['email']) > 255) {
            throw new ValidationException('auth.error.email_too_long', 'email');
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('auth.error.invalid_email', 'email');
        }
        if (isset($data['first_name']) && mb_strlen($data['first_name']) > 100) {
            throw new ValidationException('auth.error.field_too_long', 'first_name');
        }
        if (isset($data['last_name']) && mb_strlen($data['last_name']) > 100) {
            throw new ValidationException('auth.error.field_too_long', 'last_name');
        }
        $this->validatePassword($data['password']);
    }

    private function validatePassword(string $password): void
    {
        if (strlen($password) > 72) {
            throw new ValidationException('auth.error.password_too_long', 'password');
        }
        if (
            strlen($password) < $this->config['password_min_length']
            || !preg_match('/[A-Z]/', $password)
            || !preg_match('/[a-z]/', $password)
            || !preg_match('/[0-9]/', $password)
        ) {
            throw new ValidationException('auth.error.password_too_weak', 'password');
        }
    }
}
