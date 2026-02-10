<?php

namespace App\Services;

use App\Exceptions\HttpException;
use App\Exceptions\UnauthorizedException;
use App\Exceptions\ValidationException;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\UserRepository;
use Firebase\JWT\JWT;

class AuthService
{
    private UserRepository $userRepo;
    private RefreshTokenRepository $tokenRepo;
    private array $config;

    public function __construct(UserRepository $userRepo, RefreshTokenRepository $tokenRepo, array $config)
    {
        $this->userRepo = $userRepo;
        $this->tokenRepo = $tokenRepo;
        $this->config = $config;
    }

    public function register(array $data): array
    {
        $this->validateRegisterData($data);

        if ($this->userRepo->existsByEmail($data['email'])) {
            throw new HttpException('EMAIL_TAKEN', 'auth.error.email_taken', 'email', 409);
        }

        $user = $this->userRepo->create([
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_BCRYPT),
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
        ]);

        $accessToken = $this->generateAccessToken((int)$user['id']);
        $refreshToken = $this->generateRefreshToken((int)$user['id']);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
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
            'refresh_token' => $refreshToken,
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
            'refresh_token' => $refreshToken,
        ];
    }

    public function logout(int $userId): void
    {
        $this->tokenRepo->deleteAllByUserId($userId);
    }

    public function getProfile(int $userId): array
    {
        $user = $this->userRepo->findById($userId);

        if (!$user) {
            throw new UnauthorizedException('auth.error.token_invalid', 'TOKEN_INVALID');
        }

        return $user;
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
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('auth.error.invalid_email', 'email');
        }
        $this->validatePassword($data['password']);
    }

    private function validatePassword(string $password): void
    {
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
