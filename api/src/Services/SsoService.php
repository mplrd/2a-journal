<?php

namespace App\Services;

use App\Exceptions\UnauthorizedException;
use App\Exceptions\ValidationException;
use App\Repositories\SsoCodeRepository;

/**
 * Bridges sessions across the user SPA and the admin SPA via short-lived,
 * single-use codes. The user SPA hands a code to the admin SPA in the URL;
 * the admin SPA exchanges it for tokens, skipping a manual re-login.
 *
 * Codes:
 *  - 32 random bytes (256 bits), hex-encoded → 64 chars
 *  - SHA-256 hashed at rest (no plaintext in DB)
 *  - 30-second TTL — long enough for a click → load → exchange round-trip,
 *    short enough that a leaked URL is dead almost immediately
 *  - Single-use: marked used_at on first redemption, replays are rejected
 *
 * The exchange path delegates session creation to AuthService so the same
 * suspension guard, login bookkeeping, and token format are reused.
 */
class SsoService
{
    private const TTL_SECONDS = 30;

    public function __construct(
        private SsoCodeRepository $repo,
        private AuthService $authService,
    ) {}

    /**
     * Generate and store a new code for the given user. Returns the
     * plaintext (hex) code — the only place it ever exists.
     *
     * @return array{code: string, expires_in: int}
     */
    public function issueCode(int $userId): array
    {
        $code = bin2hex(random_bytes(32));
        $codeHash = hash('sha256', $code);

        $this->repo->create($codeHash, $userId, self::TTL_SECONDS);

        return [
            'code' => $code,
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    /**
     * Exchange a one-time code for a fresh session. Same response shape as
     * /auth/login: access_token, refresh_cookie, user.
     *
     * Validates the code is known, unused, and not expired. Marks it used
     * before issuing tokens so a concurrent retry cannot double-redeem.
     */
    public function exchange(string $code): array
    {
        if ($code === '') {
            throw new ValidationException('auth.error.field_required', 'code');
        }

        // Opportunistic cleanup — saves running a cron just for this.
        $this->repo->deleteExpiredOrUsed();

        // Atomic claim: redeem() returns null if the code was never valid or
        // if a concurrent request beat us to it.
        $row = $this->repo->redeem(hash('sha256', $code));

        if (!$row) {
            throw new UnauthorizedException('auth.error.sso_code_invalid', 'SSO_CODE_INVALID');
        }

        return $this->authService->issueSessionForUser((int) $row['user_id']);
    }
}
