<?php

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\UserRepository;
use PDO;

/**
 * Admin-side user management. The shape of returned users is sanitized:
 * password / refresh tokens / Stripe identifiers are never exposed.
 */
class AdminUserService
{
    public function __construct(
        private UserRepository $userRepo,
        private AuthService $authService,
        private PDO $pdo,
    ) {}

    /**
     * Paginated list with optional email search and status filter.
     *
     * @param array{search?: string, status?: 'active'|'suspended'|'all', page?: int, per_page?: int} $filters
     * @return array{data: array, meta: array}
     */
    public function list(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        $where = 'WHERE u.deleted_at IS NULL';
        $params = [];

        if (!empty($filters['search'])) {
            $where .= ' AND u.email LIKE :search';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'suspended') {
                $where .= ' AND u.suspended_at IS NOT NULL';
            } elseif ($filters['status'] === 'active') {
                $where .= ' AND u.suspended_at IS NULL';
            }
            // 'all' or anything else → no extra filter
        }

        $countSql = "SELECT COUNT(*) FROM users u $where";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT u.id, u.email, u.role, u.suspended_at, u.first_name, u.last_name,
                       u.locale, u.created_at, u.updated_at,
                       (SELECT COUNT(*) FROM trades t
                        INNER JOIN positions p ON p.id = t.position_id
                        WHERE p.user_id = u.id) AS trade_count
                FROM users u
                $where
                ORDER BY u.created_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return [
            'data' => array_map([$this, 'sanitize'], $rows),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
            ],
        ];
    }

    public function get(int $userId): array
    {
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            throw new NotFoundException('admin.error.user_not_found');
        }
        return $this->sanitize($user);
    }

    public function suspend(int $adminId, int $userId): array
    {
        if ($adminId === $userId) {
            throw new ValidationException('admin.error.cannot_self_suspend');
        }
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            throw new NotFoundException('admin.error.user_not_found');
        }

        $this->userRepo->setSuspendedAt($userId);

        return $this->sanitize($this->userRepo->findById($userId));
    }

    public function unsuspend(int $userId): array
    {
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            throw new NotFoundException('admin.error.user_not_found');
        }

        $this->userRepo->clearSuspendedAt($userId);

        return $this->sanitize($this->userRepo->findById($userId));
    }

    public function resetPassword(int $userId): void
    {
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            throw new NotFoundException('admin.error.user_not_found');
        }

        // Reuse the same flow as the public "forgot password" — generates a
        // reset token and emails it to the user. No password is set by the
        // admin directly; the user clicks the link and chooses one.
        $this->authService->forgotPassword(['email' => $user['email']]);
    }

    public function delete(int $adminId, int $userId): void
    {
        if ($adminId === $userId) {
            throw new ValidationException('admin.error.cannot_self_delete');
        }
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            throw new NotFoundException('admin.error.user_not_found');
        }

        $this->userRepo->softDelete($userId);
    }

    /**
     * Strip sensitive fields from a user row before returning to the admin.
     * Defensively keeps a whitelist of safe fields rather than blacklisting.
     */
    private function sanitize(array $user): array
    {
        $allowed = [
            'id', 'email', 'role', 'suspended_at',
            'first_name', 'last_name', 'locale', 'timezone', 'default_currency',
            'theme', 'created_at', 'updated_at',
            'email_verified_at', 'onboarding_completed_at',
            'trade_count',
        ];
        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $user)) {
                $out[$key] = $user[$key];
            }
        }
        return $out;
    }
}
