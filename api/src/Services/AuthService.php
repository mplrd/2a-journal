<?php

namespace App\Services;

use App\Exceptions\HttpException;
use App\Exceptions\UnauthorizedException;
use App\Exceptions\ValidationException;
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
    private array $config;

    public function __construct(UserRepository $userRepo, RefreshTokenRepository $tokenRepo, ?SymbolRepository $symbolRepo, ?SetupRepository $setupRepo, array $config)
    {
        $this->userRepo = $userRepo;
        $this->tokenRepo = $tokenRepo;
        $this->symbolRepo = $symbolRepo;
        $this->setupRepo = $setupRepo;
        $this->config = $config;
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

        // Seed default symbols for new user
        if ($this->symbolRepo) {
            $this->symbolRepo->seedForUser((int)$user['id']);
        }

        // Seed default setups for new user
        if ($this->setupRepo) {
            $this->setupRepo->seedForUser((int)$user['id']);
        }

        $accessToken = $this->generateAccessToken((int)$user['id']);
        $refreshToken = $this->generateRefreshToken((int)$user['id']);

        return [
            'access_token' => $accessToken,
            'refresh_cookie' => $this->buildRefreshCookie($refreshToken, $this->config['refresh_token_ttl']),
            'user' => $user,
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

        if (!$user || !password_verify($data['password'], $user['password'])) {
            throw new UnauthorizedException('auth.error.invalid_credentials', 'INVALID_CREDENTIALS');
        }

        $userId = (int)$user['id'];
        $accessToken = $this->generateAccessToken($userId);
        $refreshToken = $this->generateRefreshToken($userId);

        $profile = $this->userRepo->findById($userId);

        return [
            'access_token' => $accessToken,
            'refresh_cookie' => $this->buildRefreshCookie($refreshToken, $this->config['refresh_token_ttl']),
            'user' => $profile,
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

    private const SUPPORTED_LOCALES = ['fr', 'en'];
    private const SUPPORTED_THEMES = ['light', 'dark'];
    private const PROFILE_FIELDS = ['first_name', 'last_name', 'timezone', 'default_currency', 'theme', 'locale'];
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
        $payload = [
            'sub' => $userId,
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
