<?php

namespace App\Services;

use App\Enums\BrokerProvider;
use App\Enums\ConnectionStatus;
use App\Enums\Direction;
use App\Enums\OrderType;
use App\Enums\RobotStatus;
use App\Enums\WebhookEventStatus;
use App\Enums\WebhookRejectReason;
use App\Exceptions\BrokerOrderException;
use App\Repositories\BrokerConnectionRepository;
use App\Repositories\RobotRepository;
use App\Repositories\TradingViewAlertEventRepository;
use App\Repositories\TradingViewWebhookRepository;
use App\Services\Broker\BrokerLogger;
use App\Services\Broker\ConnectorInterface;
use App\Services\Broker\CredentialEncryptionService;

class TradingViewWebhookService
{
    public function __construct(
        private TradingViewWebhookRepository $webhookRepo,
        private RobotRepository $robotRepo,
        private TradingViewAlertEventRepository $eventRepo,
        private BrokerConnectionRepository $connectionRepo,
        private OrderService $orderService,
        private CredentialEncryptionService $crypto,
        private ConnectorInterface $ctraderConnector,
        private ConnectorInterface $metaApiConnector,
        private ConnectorInterface $ouinexConnector,
        private ConnectorInterface $bingxConnector,
    ) {}

    /**
     * Process an inbound TradingView alert. The controller is responsible for
     * always returning HTTP 200 — every outcome (rejected, processed, failed,
     * duplicate) is logged into tradingview_alert_events so the user can audit
     * the activity from the UI without revealing to the public webhook caller
     * whether a token/secret is valid.
     */
    public function process(string $token, array $payload): void
    {
        $tokenHash = hash('sha256', $token);
        $webhook = $this->webhookRepo->findByTokenHash($tokenHash);

        if ($webhook === null) {
            $this->logEvent(null, null, $payload, WebhookEventStatus::REJECTED, WebhookRejectReason::INVALID_TOKEN);
            return;
        }

        $webhookId = (int) $webhook['id'];
        $robot = $this->robotRepo->findById((int) $webhook['robot_id']);

        // Orphan webhook (robot hard-deleted) — treat as an invalid token: we
        // don't have a target account to act on.
        if ($robot === null) {
            $this->logEvent($webhookId, null, $payload, WebhookEventStatus::REJECTED, WebhookRejectReason::INVALID_TOKEN);
            return;
        }

        $robotId = (int) $robot['id'];
        $accountId = (int) $robot['account_id'];

        // The body secret is checked before the robot status so a paused robot
        // never reveals (via differing behaviour) whether the secret was right.
        $secret = isset($payload['secret']) && is_string($payload['secret']) ? $payload['secret'] : '';
        if (!hash_equals((string) $webhook['body_secret_hash'], hash('sha256', $secret))) {
            $this->logEvent($webhookId, $accountId, $payload, WebhookEventStatus::REJECTED, WebhookRejectReason::INVALID_SECRET);
            return;
        }

        // A paused (or archived) robot logs the signal but places no trade.
        if ($robot['status'] !== RobotStatus::ACTIVE->value) {
            $this->logEvent($webhookId, $accountId, $payload, WebhookEventStatus::REJECTED, WebhookRejectReason::ROBOT_PAUSED);
            return;
        }

        $externalAlertId = $this->extractAlertId($payload);

        if ($this->eventRepo->existsByWebhookAndAlertId($webhookId, $externalAlertId)) {
            // Log the DUPLICATE event WITHOUT the alert_id — the UNIQUE constraint
            // on (webhook_id, external_alert_id) is what protects us against the
            // replay in the first place, and that constraint would also block
            // this very row from being inserted. We keep the original alert_id
            // in error_message for audit.
            $this->logEvent(
                $webhookId,
                $accountId,
                $payload,
                WebhookEventStatus::DUPLICATE,
                null,
                $externalAlertId !== null ? "duplicate of alert_id={$externalAlertId}" : null,
                null,
                null,
            );
            return;
        }

        $validationError = $this->validatePayload($payload);
        if ($validationError !== null) {
            $this->logEvent($webhookId, $accountId, $payload, WebhookEventStatus::REJECTED, WebhookRejectReason::INVALID_PAYLOAD, $validationError, null, $externalAlertId);
            $this->robotRepo->recordTrigger($robotId, false);
            return;
        }

        $connection = $this->connectionRepo->findByAccountId($accountId);
        if ($connection === null) {
            $this->logEvent($webhookId, $accountId, $payload, WebhookEventStatus::REJECTED, WebhookRejectReason::NO_BROKER, null, null, $externalAlertId);
            $this->robotRepo->recordTrigger($robotId, false);
            return;
        }

        if ($connection['status'] !== ConnectionStatus::ACTIVE->value) {
            $this->logEvent($webhookId, $accountId, $payload, WebhookEventStatus::REJECTED, WebhookRejectReason::BROKER_INACTIVE, null, null, $externalAlertId);
            $this->robotRepo->recordTrigger($robotId, false);
            return;
        }

        $order = $this->orderService->createFromWebhook((int) $webhook['user_id'], [
            'account_id' => $accountId,
            'direction' => $payload['direction'],
            'symbol' => $payload['symbol'],
            'entry_price' => (float) $payload['entry_price'],
            'size' => (float) $payload['size'],
            'setup' => $payload['setup'] ?? ['TradingView'],
            'sl_points' => (float) $payload['sl_points'],
            'be_points' => $payload['be_points'] ?? null,
            'be_size' => $payload['be_size'] ?? null,
            'targets' => $payload['targets'] ?? null,
            'notes' => $payload['notes'] ?? null,
        ]);

        $orderId = (int) $order['id'];

        try {
            $credentials = $this->crypto->decrypt(
                $connection['credentials_encrypted'],
                $connection['credentials_iv']
            );

            $connector = $this->getConnector($connection['provider']);
            $brokerResult = $connector->placeOrder($credentials, [
                'symbol' => $payload['symbol'],
                'direction' => $payload['direction'],
                'order_type' => $payload['order_type'] ?? OrderType::MARKET->value,
                'size' => (float) $payload['size'],
                'entry_price' => isset($payload['entry_price']) ? (float) $payload['entry_price'] : null,
                'sl_price' => isset($order['sl_price']) ? (float) $order['sl_price'] : null,
                'tp_prices' => $this->extractTpPrices($order['targets'] ?? null),
                'client_order_id' => $externalAlertId,
            ]);

            $this->logEvent(
                $webhookId,
                $accountId,
                $payload,
                WebhookEventStatus::PROCESSED,
                null,
                null,
                $orderId,
                $externalAlertId,
            );
            $this->robotRepo->recordTrigger($robotId, true);
        } catch (BrokerOrderException $e) {
            $this->logEvent(
                $webhookId,
                $accountId,
                $payload,
                WebhookEventStatus::FAILED,
                WebhookRejectReason::BROKER_ERROR,
                sprintf('[%s] %s', $e->getProviderCode(), $e->getMessage()),
                $orderId,
                $externalAlertId,
            );
            $this->robotRepo->recordTrigger($robotId, false);
        }
    }

    private function extractAlertId(array $payload): ?string
    {
        if (!isset($payload['alert_id'])) {
            return null;
        }
        $value = is_scalar($payload['alert_id']) ? (string) $payload['alert_id'] : '';
        $value = trim($value);
        return $value !== '' ? mb_substr($value, 0, 120) : null;
    }

    private function validatePayload(array $payload): ?string
    {
        foreach (['symbol', 'direction', 'entry_price', 'size', 'sl_points'] as $required) {
            if (!isset($payload[$required]) || $payload[$required] === '') {
                return "missing required field: {$required}";
            }
        }

        if (!Direction::tryFrom((string) $payload['direction'])) {
            return 'invalid direction (expected BUY or SELL)';
        }

        if (isset($payload['order_type']) && !OrderType::tryFrom((string) $payload['order_type'])) {
            return 'invalid order_type (expected MARKET, LIMIT, or STOP)';
        }

        if ((float) $payload['entry_price'] <= 0) {
            return 'entry_price must be > 0';
        }

        if ((float) $payload['size'] <= 0) {
            return 'size must be > 0';
        }

        if ((float) $payload['sl_points'] <= 0) {
            return 'sl_points must be > 0';
        }

        return null;
    }

    private function extractTpPrices(mixed $targetsField): array
    {
        if ($targetsField === null) {
            return [];
        }
        $targets = is_string($targetsField) ? json_decode($targetsField, true) : $targetsField;
        if (!is_array($targets)) {
            return [];
        }

        $prices = [];
        foreach ($targets as $target) {
            if (is_array($target) && isset($target['price'])) {
                $prices[] = (float) $target['price'];
            }
        }
        return $prices;
    }

    private function getConnector(string $provider): ConnectorInterface
    {
        return match (BrokerProvider::from($provider)) {
            BrokerProvider::CTRADER => $this->ctraderConnector,
            BrokerProvider::METAAPI => $this->metaApiConnector,
            BrokerProvider::OUINEX => $this->ouinexConnector,
            BrokerProvider::BINGX => $this->bingxConnector,
        };
    }

    private function logEvent(
        ?int $webhookId,
        ?int $accountId,
        array $payload,
        WebhookEventStatus $status,
        ?WebhookRejectReason $reason = null,
        ?string $errorMessage = null,
        ?int $orderId = null,
        ?string $externalAlertId = null,
    ): void {
        $sanitized = $payload;
        if (isset($sanitized['secret'])) {
            $sanitized['secret'] = '***';
        }

        $this->eventRepo->create([
            'webhook_id' => $webhookId,
            'account_id' => $accountId,
            'external_alert_id' => $externalAlertId,
            'payload_raw' => $sanitized,
            'status' => $status->value,
            'reject_reason' => $reason?->value,
            'error_message' => $errorMessage,
            'created_order_id' => $orderId,
        ]);

        // Mirror non-OK outcomes to stderr so they show up in Railway logs
        // alongside the broker connector failures. The DB row stays the
        // user-facing audit; this is the ops-facing trail.
        if (in_array($status, [WebhookEventStatus::REJECTED, WebhookEventStatus::FAILED], true)) {
            BrokerLogger::failure('tradingview', 'alert_' . strtolower($status->value), [
                'webhook_id' => $webhookId,
                'account_id' => $accountId,
                'reject_reason' => $reason?->value,
                'alert_id' => $externalAlertId,
                'order_id' => $orderId,
                'msg' => $errorMessage,
            ]);
        }
    }
}
