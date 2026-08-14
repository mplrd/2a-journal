<?php

namespace App\Services;

use App\Enums\BrokerProvider;
use App\Enums\ConnectionStatus;
use App\Enums\Direction;
use App\Enums\EntityType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TriggerType;
use App\Enums\RobotStatus;
use App\Enums\WebhookAction;
use App\Enums\WebhookEventStatus;
use App\Enums\WebhookRejectReason;
use App\Exceptions\BrokerOrderException;
use App\Repositories\BrokerConnectionRepository;
use App\Repositories\OrderRepository;
use App\Repositories\RobotRepository;
use App\Repositories\StatusHistoryRepository;
use App\Repositories\TradingPlanRepository;
use App\Repositories\TradingViewAlertEventRepository;
use App\Repositories\TradingViewWebhookRepository;
use App\Services\Broker\BrokerCredentialStore;
use App\Services\Broker\BrokerLogger;
use App\Services\Broker\ConnectorInterface;
use DateTimeImmutable;

class TradingViewWebhookService
{
    public function __construct(
        private TradingViewWebhookRepository $webhookRepo,
        private RobotRepository $robotRepo,
        private TradingViewAlertEventRepository $eventRepo,
        private BrokerConnectionRepository $connectionRepo,
        private OrderService $orderService,
        private OrderRepository $orderRepo,
        private StatusHistoryRepository $historyRepo,
        private BrokerCredentialStore $credentialStore,
        private ConnectorInterface $ctraderConnector,
        private ConnectorInterface $metaApiConnector,
        private ConnectorInterface $ouinexConnector,
        private ConnectorInterface $bingxConnector,
        private TradingPlanRepository $planRepo,
        private PlanEvaluator $planEvaluator,
        private SignalRiskCalculator $riskCalculator,
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

        $action = $this->extractAction($payload);
        if ($action === null) {
            $this->logEvent($webhookId, $accountId, $payload, WebhookEventStatus::REJECTED, WebhookRejectReason::UNSUPPORTED_ACTION, 'unknown action', null, $externalAlertId);
            $this->robotRepo->recordTrigger($robotId, false);
            return;
        }

        $validationError = $this->validatePayload($payload, $action);
        if ($validationError !== null) {
            $this->logEvent($webhookId, $accountId, $payload, WebhookEventStatus::REJECTED, WebhookRejectReason::INVALID_PAYLOAD, $validationError, null, $externalAlertId);
            $this->robotRepo->recordTrigger($robotId, false);
            return;
        }

        // Trading-plan gate — OPEN only. MODIFY/CLOSE/CANCEL manage an order the
        // robot already opened, so they always bypass the plan (never trap a
        // live position). A robot with no plan executes every signal.
        if ($action === WebhookAction::OPEN) {
            $planReason = $this->planRejectionReason($robotId, $accountId, (int) $webhook['user_id'], $payload);
            if ($planReason !== null) {
                $this->logEvent($webhookId, $accountId, $payload, WebhookEventStatus::REJECTED, WebhookRejectReason::OUT_OF_PLAN, $planReason, null, $externalAlertId);
                $this->robotRepo->recordTrigger($robotId, false);
                return;
            }
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

        // Context shared by every action handler.
        $ctx = [
            'webhook_id' => $webhookId,
            'robot_id' => $robotId,
            'account_id' => $accountId,
            'user_id' => (int) $webhook['user_id'],
            'connection' => $connection,
            'payload' => $payload,
            'alert_id' => $externalAlertId,
        ];

        match ($action) {
            WebhookAction::OPEN => $this->handleOpen($ctx),
            WebhookAction::MODIFY => $this->handleModify($ctx),
            WebhookAction::CLOSE => $this->handleClose($ctx),
            WebhookAction::CANCEL => $this->handleCancel($ctx),
        };
    }

    private function handleOpen(array $ctx): void
    {
        [$webhookId, $robotId, $accountId, $payload, $alertId] =
            [$ctx['webhook_id'], $ctx['robot_id'], $ctx['account_id'], $ctx['payload'], $ctx['alert_id']];

        $order = $this->orderService->createFromWebhook($ctx['user_id'], [
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
        $clientOrderId = $this->extractClientOrderId($payload);

        try {
            $brokerResult = $this->connectorFor($ctx)->placeOrder($this->credentials($ctx), [
                'symbol' => $payload['symbol'],
                'direction' => $payload['direction'],
                'order_type' => $payload['order_type'] ?? OrderType::MARKET->value,
                'size' => (float) $payload['size'],
                'entry_price' => isset($payload['entry_price']) ? (float) $payload['entry_price'] : null,
                'sl_price' => isset($order['sl_price']) ? (float) $order['sl_price'] : null,
                'tp_prices' => $this->extractTpPrices($order['targets'] ?? null),
                'client_order_id' => $clientOrderId ?? $alertId,
            ]);

            // Persist correlation so MODIFY/CLOSE/CANCEL can find this order.
            $this->orderRepo->setBrokerCorrelation(
                $orderId,
                $clientOrderId,
                isset($brokerResult['external_order_id']) ? (string) $brokerResult['external_order_id'] : null,
            );

            $this->logEvent($webhookId, $accountId, $payload, WebhookEventStatus::PROCESSED, null, null, $orderId, $alertId);
            $this->robotRepo->recordTrigger($robotId, true);
        } catch (BrokerOrderException $e) {
            $this->logEvent($webhookId, $accountId, $payload, WebhookEventStatus::FAILED, WebhookRejectReason::BROKER_ERROR, $this->brokerErr($e), $orderId, $alertId);
            $this->robotRepo->recordTrigger($robotId, false);
        }
    }

    private function handleModify(array $ctx): void
    {
        $order = $this->resolveOrder($ctx);
        if ($order === null) {
            return; // resolveOrder already logged ORDER_NOT_FOUND
        }
        $payload = $ctx['payload'];

        try {
            $this->connectorFor($ctx)->modifyOrder($this->credentials($ctx), [
                'broker_order_id' => (string) ($order['broker_order_id'] ?? ''),
                'symbol' => $payload['symbol'] ?? null,
                'sl_price' => isset($payload['sl_price']) ? (float) $payload['sl_price'] : null,
                'tp_price' => isset($payload['tp_price']) ? (float) $payload['tp_price'] : null,
            ]);
            $this->logEvent($ctx['webhook_id'], $ctx['account_id'], $payload, WebhookEventStatus::PROCESSED, null, 'MODIFY', (int) $order['id'], $ctx['alert_id']);
            $this->robotRepo->recordTrigger($ctx['robot_id'], true);
        } catch (BrokerOrderException $e) {
            $this->logEvent($ctx['webhook_id'], $ctx['account_id'], $payload, WebhookEventStatus::FAILED, WebhookRejectReason::BROKER_ERROR, $this->brokerErr($e), (int) $order['id'], $ctx['alert_id']);
            $this->robotRepo->recordTrigger($ctx['robot_id'], false);
        }
    }

    private function handleClose(array $ctx): void
    {
        $order = $this->resolveOrder($ctx);
        if ($order === null) {
            return;
        }
        $payload = $ctx['payload'];
        $sizeOverride = isset($payload['size']) && (float) $payload['size'] > 0 ? (float) $payload['size'] : null;

        try {
            $this->connectorFor($ctx)->closePosition(
                $this->credentials($ctx),
                (string) ($order['broker_order_id'] ?? ''),
                $sizeOverride,
            );
            $this->markOrderCancelled((int) $order['id'], (string) $order['status']);
            $this->logEvent($ctx['webhook_id'], $ctx['account_id'], $payload, WebhookEventStatus::PROCESSED, null, 'CLOSE', (int) $order['id'], $ctx['alert_id']);
            $this->robotRepo->recordTrigger($ctx['robot_id'], true);
        } catch (BrokerOrderException $e) {
            $this->logEvent($ctx['webhook_id'], $ctx['account_id'], $payload, WebhookEventStatus::FAILED, WebhookRejectReason::BROKER_ERROR, $this->brokerErr($e), (int) $order['id'], $ctx['alert_id']);
            $this->robotRepo->recordTrigger($ctx['robot_id'], false);
        }
    }

    private function handleCancel(array $ctx): void
    {
        $order = $this->resolveOrder($ctx);
        if ($order === null) {
            return;
        }
        $payload = $ctx['payload'];

        try {
            $this->connectorFor($ctx)->cancelOrder($this->credentials($ctx), (string) ($order['broker_order_id'] ?? ''));
            $this->markOrderCancelled((int) $order['id'], (string) $order['status']);
            $this->logEvent($ctx['webhook_id'], $ctx['account_id'], $payload, WebhookEventStatus::PROCESSED, null, 'CANCEL', (int) $order['id'], $ctx['alert_id']);
            $this->robotRepo->recordTrigger($ctx['robot_id'], true);
        } catch (BrokerOrderException $e) {
            $this->logEvent($ctx['webhook_id'], $ctx['account_id'], $payload, WebhookEventStatus::FAILED, WebhookRejectReason::BROKER_ERROR, $this->brokerErr($e), (int) $order['id'], $ctx['alert_id']);
            $this->robotRepo->recordTrigger($ctx['robot_id'], false);
        }
    }

    /**
     * Confront an OPEN signal with the robot's active plans (docs/83).
     * Returns null when the robot has no plan (no filter) or the signal is
     * applicable to AT LEAST ONE plan (OR); otherwise the reason from the
     * first plan it failed — surfaced to the audit event as error_message.
     */
    private function planRejectionReason(int $robotId, int $accountId, int $userId, array $payload): ?string
    {
        $plans = $this->planRepo->findAssembledActivePlansForRobot($robotId);
        if ($plans === []) {
            return null;
        }

        $direction = (string) $payload['direction'];
        $symbol = (string) $payload['symbol'];
        $entryPrice = (float) $payload['entry_price'];
        $riskPercent = $this->riskCalculator->computePercent(
            $userId,
            $accountId,
            $symbol,
            (float) $payload['size'],
            (float) $payload['sl_points'],
        );
        $now = new DateTimeImmutable('now');

        $firstReason = null;
        foreach ($plans as $plan) {
            $reason = $this->planEvaluator->evaluate($plan, $direction, $symbol, $entryPrice, $riskPercent, $now);
            if ($reason === null) {
                return null; // applicable to this plan → accept (OR across plans)
            }
            $firstReason ??= $reason;
        }
        return $firstReason;
    }

    /** Resolve a follow-up signal to its live order, or log ORDER_NOT_FOUND. */
    private function resolveOrder(array $ctx): ?array
    {
        $clientOrderId = $this->extractClientOrderId($ctx['payload']);
        $order = $clientOrderId !== null
            ? $this->orderRepo->findLiveByClientOrderId($ctx['account_id'], $clientOrderId)
            : null;
        if ($order === null) {
            $this->logEvent($ctx['webhook_id'], $ctx['account_id'], $ctx['payload'], WebhookEventStatus::REJECTED, WebhookRejectReason::ORDER_NOT_FOUND, "no live order for client_order_id={$clientOrderId}", null, $ctx['alert_id']);
            $this->robotRepo->recordTrigger($ctx['robot_id'], false);
        }
        return $order;
    }

    /**
     * The connection's own credentials with the user's shared app credentials
     * underneath. Reading the connection row alone would hand the connector a
     * cTrader blob with no access token in it (docs/91).
     */
    private function credentials(array $ctx): array
    {
        return $this->credentialStore->forConnection($ctx['connection']);
    }

    private function connectorFor(array $ctx): ConnectorInterface
    {
        return $this->getConnector($ctx['connection']['provider']);
    }

    private function brokerErr(BrokerOrderException $e): string
    {
        return sprintf('[%s] %s', $e->getProviderCode(), $e->getMessage());
    }

    /** Reflect a broker-side close/cancel in the local order + status history. */
    private function markOrderCancelled(int $orderId, string $previousStatus): void
    {
        $this->orderRepo->updateStatus($orderId, OrderStatus::CANCELLED->value);
        $this->historyRepo->create([
            'entity_type' => EntityType::ORDER->value,
            'entity_id' => $orderId,
            'previous_status' => $previousStatus,
            'new_status' => OrderStatus::CANCELLED->value,
            'user_id' => null,
            'trigger_type' => TriggerType::WEBHOOK->value,
        ]);
    }

    private function extractAlertId(array $payload): ?string
    {
        return $this->extractKey($payload, 'alert_id');
    }

    /**
     * Stable correlation key the indicator supplies at OPEN and re-emits on
     * MODIFY/CLOSE/CANCEL. Distinct from alert_id (which is unique per signal,
     * used for dedup) — client_order_id is stable across an order's lifecycle.
     */
    private function extractClientOrderId(array $payload): ?string
    {
        return $this->extractKey($payload, 'client_order_id');
    }

    private function extractKey(array $payload, string $field): ?string
    {
        if (!isset($payload[$field]) || !is_scalar($payload[$field])) {
            return null;
        }
        $value = trim((string) $payload[$field]);
        return $value !== '' ? mb_substr($value, 0, 120) : null;
    }

    /** Resolve the requested action; defaults to OPEN, null if unknown value. */
    private function extractAction(array $payload): ?WebhookAction
    {
        if (!isset($payload['action']) || $payload['action'] === '') {
            return WebhookAction::OPEN;
        }
        return WebhookAction::tryFrom(strtoupper((string) $payload['action']));
    }

    /** Validate by action. OPEN needs the full order; the rest need correlation. */
    private function validatePayload(array $payload, WebhookAction $action): ?string
    {
        if ($action === WebhookAction::OPEN) {
            return $this->validateOpenPayload($payload);
        }

        // MODIFY / CLOSE / CANCEL all need a client_order_id to resolve the order.
        if ($this->extractClientOrderId($payload) === null) {
            return 'missing required field: client_order_id';
        }
        if ($action === WebhookAction::MODIFY) {
            // MODIFY works with absolute prices (no entry reference to convert
            // points at amend time): the indicator sends sl_price and/or tp_price.
            $hasSl = isset($payload['sl_price']) && (float) $payload['sl_price'] > 0;
            $hasTp = isset($payload['tp_price']) && (float) $payload['tp_price'] > 0;
            if (!$hasSl && !$hasTp) {
                return 'MODIFY requires at least one of: sl_price, tp_price';
            }
        }

        return null;
    }

    private function validateOpenPayload(array $payload): ?string
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
