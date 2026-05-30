<?php

namespace App\Services;

use App\Enums\OrderType;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\TradingViewAlertEventRepository;
use App\Repositories\TradingViewWebhookRepository;

class AccountWebhookService
{
    /** Maximum number of TradingView webhooks per account. Hard cap, not a quota. */
    public const MAX_PER_ACCOUNT = 10;

    public function __construct(
        private TradingViewWebhookRepository $webhookRepo,
        private TradingViewAlertEventRepository $eventRepo,
        private AccountRepository $accountRepo,
        private string $baseWebhookUrl,
    ) {}

    public function listForAccount(int $userId, int $accountId): array
    {
        $this->ensureAccountOwned($userId, $accountId);
        return $this->webhookRepo->findAllByAccountId($accountId);
    }

    /**
     * Create a new webhook. The URL token + body secret are returned ONCE in
     * the response — we only persist their SHA-256 hashes, so neither can be
     * recovered. If the user loses them, the only recovery is delete+recreate.
     *
     * @return array{webhook: array, url: string, body_secret: string, template: array}
     */
    public function create(int $userId, int $accountId, array $data): array
    {
        $this->ensureAccountOwned($userId, $accountId);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new ValidationException('webhook.error.invalid_name', 'name');
        }

        $existing = $this->webhookRepo->findAllByAccountId($accountId);
        if (count($existing) >= self::MAX_PER_ACCOUNT) {
            throw new ValidationException('webhook.error.too_many', 'account_id');
        }

        $urlToken = bin2hex(random_bytes(24));
        $bodySecret = bin2hex(random_bytes(24));

        $webhook = $this->webhookRepo->create([
            'user_id' => $userId,
            'account_id' => $accountId,
            'name' => $name,
            'url_token_hash' => hash('sha256', $urlToken),
            'body_secret_hash' => hash('sha256', $bodySecret),
        ]);

        $url = rtrim($this->baseWebhookUrl, '/') . '/' . $urlToken;

        return [
            'webhook' => $webhook,
            'url' => $url,
            'body_secret' => $bodySecret,
            'template' => $this->buildTemplate($bodySecret),
        ];
    }

    public function revoke(int $userId, int $accountId, int $webhookId): void
    {
        $webhook = $this->findOwnedWebhook($userId, $accountId, $webhookId);
        $this->webhookRepo->revoke((int) $webhook['id']);
    }

    public function listEvents(int $userId, int $accountId, int $webhookId, int $page = 1, int $perPage = 50): array
    {
        $webhook = $this->findOwnedWebhook($userId, $accountId, $webhookId);

        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $events = $this->eventRepo->findAllByWebhookId((int) $webhook['id'], $perPage, $offset);
        $total = $this->eventRepo->countByWebhookId((int) $webhook['id']);

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
            throw new ForbiddenException('webhook.error.forbidden');
        }
    }

    private function findOwnedWebhook(int $userId, int $accountId, int $webhookId): array
    {
        $this->ensureAccountOwned($userId, $accountId);
        $webhook = $this->webhookRepo->findById($webhookId);
        if (!$webhook || (int) $webhook['account_id'] !== $accountId) {
            throw new NotFoundException('webhook.error.not_found');
        }
        if ((int) $webhook['user_id'] !== $userId) {
            throw new ForbiddenException('webhook.error.forbidden');
        }
        return $webhook;
    }

    /**
     * Canonical JSON template the user pastes into their TradingView alert
     * "Message" field. TradingView substitutes {{...}} placeholders before
     * sending the POST. The `secret` is baked in as a literal string — TV
     * cannot compute crypto at send time, so this is a static second factor
     * on top of the URL token.
     */
    private function buildTemplate(string $bodySecret): array
    {
        return [
            'secret' => $bodySecret,
            'alert_id' => '{{ticker}}-{{interval}}-{{timenow}}',
            'symbol' => '{{ticker}}',
            'direction' => 'BUY',
            'order_type' => OrderType::MARKET->value,
            'entry_price' => '{{close}}',
            'size' => 1.0,
            'sl_points' => 50,
            'targets' => [
                ['points' => 100, 'size' => 0.5],
                ['points' => 200, 'size' => 0.5],
            ],
            'setup' => ['TradingView'],
            'notes' => 'TradingView alert {{strategy.order.action}}',
        ];
    }
}
