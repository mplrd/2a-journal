<?php

namespace App\Services;

use App\Enums\OrderType;
use App\Enums\RobotStatus;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\RobotRepository;
use App\Repositories\TradingViewAlertEventRepository;
use App\Repositories\TradingViewWebhookRepository;

/**
 * Business logic for trading robots (docs/70-robots.md). A robot owns one
 * TradingView webhook and points to one account. v1 has no trading plan: the
 * robot executes every received signal unless it is paused.
 */
class RobotService
{
    /** Hard cap of active robots per account. */
    public const MAX_PER_ACCOUNT = 10;

    /** Placeholder shown in the read-only detail where a secret would be. */
    private const MASK = '••••••••••••';

    public function __construct(
        private RobotRepository $robotRepo,
        private TradingViewWebhookRepository $webhookRepo,
        private TradingViewAlertEventRepository $eventRepo,
        private AccountRepository $accountRepo,
        private string $baseWebhookUrl,
    ) {}

    /** All non-archived robots of the user, across every account. */
    public function listForUser(int $userId): array
    {
        return $this->robotRepo->findAllByUserId($userId);
    }

    public function getForUser(int $userId, int $robotId): array
    {
        return $this->findOwnedRobot($userId, $robotId);
    }

    /**
     * Robot detail for the read-only view: the robot row plus the masked
     * webhook info. URL token and body secret are stored hashed and cannot be
     * recovered — we expose the base URL, a masked token and a template with a
     * masked secret. To get usable credentials again, the user regenerates.
     *
     * @return array{robot: array, webhook: array}
     */
    public function getDetailForUser(int $userId, int $robotId): array
    {
        $robot = $this->findOwnedRobot($userId, $robotId);
        $hasWebhook = $this->webhookRepo->findByRobotId($robotId) !== null;

        return [
            'robot' => $robot,
            'webhook' => [
                'exists' => $hasWebhook,
                'url_masked' => rtrim($this->baseWebhookUrl, '/') . '/' . self::MASK,
                'secret_masked' => self::MASK,
                'template' => $this->buildTemplate(self::MASK),
            ],
        ];
    }

    /**
     * Create a robot + its webhook. The URL token and body secret are returned
     * ONCE — only their SHA-256 hashes are persisted, so neither can be
     * recovered. Lost credentials → regenerate.
     *
     * @return array{robot: array, url: string, body_secret: string, template: array}
     */
    public function create(int $userId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new ValidationException('robot.error.invalid_name', 'name');
        }

        $accountId = (int) ($data['account_id'] ?? 0);
        $this->ensureAccountOwned($userId, $accountId);

        if ($this->robotRepo->countByAccountId($accountId) >= self::MAX_PER_ACCOUNT) {
            throw new ValidationException('robot.error.too_many', 'account_id');
        }

        $robot = $this->robotRepo->create([
            'user_id' => $userId,
            'account_id' => $accountId,
            'name' => $name,
        ]);

        return [
            'robot' => $robot,
            ...$this->provisionWebhook($userId, (int) $robot['id'], $name),
        ];
    }

    /**
     * Issue a fresh URL token + body secret for an existing robot, invalidating
     * the previous ones (the old webhook row is dropped). Returned ONCE — same
     * one-shot contract as creation. Lets a user recover usable credentials
     * without ever storing a secret in clear.
     *
     * @return array{robot: array, url: string, body_secret: string, template: array}
     */
    public function regenerate(int $userId, int $robotId): array
    {
        $robot = $this->findOwnedRobot($userId, $robotId);
        $this->webhookRepo->deleteByRobotId((int) $robot['id']);

        return [
            'robot' => $robot,
            ...$this->provisionWebhook($userId, (int) $robot['id'], (string) $robot['name']),
        ];
    }

    /**
     * Generate + persist (hashed) a webhook for a robot and return the clear
     * credentials once.
     *
     * @return array{url: string, body_secret: string, template: array}
     */
    private function provisionWebhook(int $userId, int $robotId, string $name): array
    {
        $urlToken = bin2hex(random_bytes(24));
        $bodySecret = bin2hex(random_bytes(24));

        $this->webhookRepo->create([
            'user_id' => $userId,
            'robot_id' => $robotId,
            'name' => $name,
            'url_token_hash' => hash('sha256', $urlToken),
            'body_secret_hash' => hash('sha256', $bodySecret),
        ]);

        return [
            'url' => rtrim($this->baseWebhookUrl, '/') . '/' . $urlToken,
            'body_secret' => $bodySecret,
            'template' => $this->buildTemplate($bodySecret),
        ];
    }

    /** ACTIVE ↔ PAUSED (and ARCHIVED). Validates the target status. */
    public function changeStatus(int $userId, int $robotId, ?string $status): array
    {
        $robot = $this->findOwnedRobot($userId, $robotId);
        $newStatus = RobotStatus::tryFrom((string) $status);
        if ($newStatus === null) {
            throw new ValidationException('robot.error.invalid_status', 'status');
        }
        $this->robotRepo->updateStatus((int) $robot['id'], $newStatus->value);

        return $this->findOwnedRobot($userId, $robotId);
    }

    /** Soft-delete: ARCHIVED keeps the signal history. */
    public function archive(int $userId, int $robotId): void
    {
        $robot = $this->findOwnedRobot($userId, $robotId);
        $this->robotRepo->updateStatus((int) $robot['id'], RobotStatus::ARCHIVED->value);
    }

    public function listEvents(int $userId, int $robotId, int $page = 1, int $perPage = 50): array
    {
        $this->findOwnedRobot($userId, $robotId);
        $webhook = $this->webhookRepo->findByRobotId($robotId);
        if ($webhook === null) {
            return ['data' => [], 'meta' => ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 0]];
        }

        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $webhookId = (int) $webhook['id'];

        $events = $this->eventRepo->findAllByWebhookId($webhookId, $perPage, $offset);
        $total = $this->eventRepo->countByWebhookId($webhookId);

        return [
            'data' => $events,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    private function ensureAccountOwned(int $userId, int $accountId): void
    {
        $account = $this->accountRepo->findById($accountId);
        if (!$account) {
            throw new NotFoundException('accounts.error.not_found');
        }
        if ((int) $account['user_id'] !== $userId) {
            throw new ForbiddenException('robot.error.forbidden');
        }
    }

    private function findOwnedRobot(int $userId, int $robotId): array
    {
        $robot = $this->robotRepo->findById($robotId);
        if (!$robot || $robot['status'] === RobotStatus::ARCHIVED->value) {
            throw new NotFoundException('robot.error.not_found');
        }
        if ((int) $robot['user_id'] !== $userId) {
            throw new ForbiddenException('robot.error.forbidden');
        }
        return $robot;
    }

    /**
     * Example JSON the user adapts in their TradingView alert "Message" field.
     *
     * This is a PATTERN, not a fixed payload: every trading value is a
     * TradingView placeholder ({{...}}) that the user's indicator fills in at
     * fire time — the app never invents levels. The ONLY literal is `secret`
     * (the static second factor on top of the URL token).
     *
     * `targets` is a free-length list (0..N): a serious Pine indicator builds
     * the array itself via alert()/str.format and emits as many take-profit
     * legs as it uses — the two shown here are just an illustration of the
     * { points, size } shape the backend expects.
     *
     * The placeholder names below ({{plot_"..."}}, {{strategy.*}}) are examples;
     * which one carries which value is up to the indicator. Required fields the
     * indicator MUST resolve: symbol, direction (BUY/SELL), entry_price, size,
     * sl_points. Optional: order_type, targets, be_points, be_size, setup, notes.
     */
    private function buildTemplate(string $bodySecret): array
    {
        return [
            'secret' => $bodySecret,
            'alert_id' => '{{ticker}}-{{interval}}-{{timenow}}',
            'symbol' => '{{ticker}}',
            'direction' => '{{strategy.order.action}}',
            'order_type' => OrderType::MARKET->value,
            'entry_price' => '{{close}}',
            'size' => '{{strategy.order.contracts}}',
            'sl_points' => '{{plot_"SL points"}}',
            'targets' => [
                ['points' => '{{plot_"TP1 points"}}', 'size' => '{{plot_"TP1 size"}}'],
                ['points' => '{{plot_"TP2 points"}}', 'size' => '{{plot_"TP2 size"}}'],
            ],
            'setup' => ['{{strategy.order.id}}'],
            'notes' => '{{strategy.order.alert_message}}',
        ];
    }
}
