<?php

namespace App\Services;

use App\Enums\Direction;
use App\Enums\EntityType;
use App\Enums\OrderStatus;
use App\Enums\PlanAdherence;
use App\Enums\PositionType;
use App\Enums\TradeStatus;
use App\Enums\TriggerType;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PositionRepository;
use App\Repositories\SetupRepository;
use App\Repositories\StatusHistoryRepository;
use App\Repositories\TradeRepository;
use App\Services\Broker\BrokerTargetBuilder;
use App\Repositories\TradingPlanRepository;
use DateTimeImmutable;
use Throwable;

class OrderService
{
    private OrderRepository $orderRepo;
    private PositionRepository $positionRepo;
    private AccountRepository $accountRepo;
    private StatusHistoryRepository $historyRepo;
    private TradeRepository $tradeRepo;
    private ?SetupRepository $setupRepo;
    private ?TradingPlanRepository $planRepo;
    private ?PlanEvaluator $planEvaluator;
    private ?SignalRiskCalculator $riskCalculator;

    public function __construct(
        OrderRepository $orderRepo,
        PositionRepository $positionRepo,
        AccountRepository $accountRepo,
        StatusHistoryRepository $historyRepo,
        TradeRepository $tradeRepo,
        ?SetupRepository $setupRepo = null,
        ?TradingPlanRepository $planRepo = null,
        ?PlanEvaluator $planEvaluator = null,
        ?SignalRiskCalculator $riskCalculator = null
    ) {
        $this->orderRepo = $orderRepo;
        $this->positionRepo = $positionRepo;
        $this->accountRepo = $accountRepo;
        $this->historyRepo = $historyRepo;
        $this->tradeRepo = $tradeRepo;
        $this->setupRepo = $setupRepo;
        $this->planRepo = $planRepo;
        $this->planEvaluator = $planEvaluator;
        $this->riskCalculator = $riskCalculator;
    }

    public function create(int $userId, array $data): array
    {
        return $this->createInternal($userId, $data, TriggerType::MANUAL);
    }

    /**
     * Create an order on behalf of an automated trigger (e.g. TradingView
     * webhook). Same validation/derivation rules as create(), but the
     * status_history row carries TriggerType::WEBHOOK.
     */
    public function createFromWebhook(int $userId, array $data): array
    {
        return $this->createInternal($userId, $data, TriggerType::WEBHOOK);
    }

    private function createInternal(int $userId, array $data, TriggerType $trigger): array
    {
        // Validate account ownership
        $this->validateRequired($data, 'account_id', 'orders.error.field_required');
        $accountId = (int) $data['account_id'];
        $this->validateId($accountId);
        $account = $this->accountRepo->findById($accountId);
        if (!$account) {
            throw new NotFoundException('accounts.error.not_found');
        }
        if ((int) $account['user_id'] !== $userId) {
            throw new ForbiddenException('orders.error.account_forbidden');
        }

        // Validate position fields
        $this->validatePositionFields($data);

        // Validate expires_at if present
        if (!empty($data['expires_at'])) {
            $expiresAt = strtotime($data['expires_at']);
            if ($expiresAt === false || $expiresAt <= time()) {
                throw new ValidationException('orders.error.invalid_expires_at', 'expires_at');
            }
        }

        // Calculate derived prices
        $direction = $data['direction'];
        $entryPrice = (float) $data['entry_price'];
        $slPoints = (float) $data['sl_points'];

        $slPrice = $this->calculateSlPrice($entryPrice, $slPoints, $direction);

        $bePrice = null;
        if (!empty($data['be_points'])) {
            $bePrice = $this->calculateBePrice($entryPrice, (float) $data['be_points'], $direction);
        }

        $targets = null;
        if (!empty($data['targets'])) {
            $targetsData = is_string($data['targets']) ? json_decode($data['targets'], true) : $data['targets'];
            if (is_array($targetsData)) {
                $targetsData = $this->calculateTargetPrices($targetsData, $entryPrice, $direction);
                // An order the user places owns its objectives — no broker
                // marker, so the sync will never rewrite them.
                $targetsData = BrokerTargetBuilder::withoutSource($targetsData);
                $targets = json_encode($targetsData);
            }
        }

        // Auto-create unknown setups in dictionary
        if ($this->setupRepo) {
            $this->setupRepo->ensureExist($userId, $data['setup']);
        }

        // Trading-plan adherence, frozen at order placement (docs/83). Optional;
        // carried by the position, so the trade inherits it verbatim on execute.
        $planId = $this->normalizePlanId($data['plan_id'] ?? null);
        $adherence = $this->evaluatePlanAdherence(
            $userId, $accountId, $planId, $direction, (string) $data['symbol'], $entryPrice, (float) $data['size'], $slPoints
        );

        // Create position
        $position = $this->positionRepo->create([
            'user_id' => $userId,
            'account_id' => $accountId,
            'direction' => $direction,
            'symbol' => $data['symbol'],
            'entry_price' => $entryPrice,
            'size' => (float) $data['size'],
            'setup' => json_encode($data['setup']),
            'plan_id' => $adherence['plan_id'],
            'plan_adherence' => $adherence['plan_adherence'],
            'plan_adherence_reason' => $adherence['plan_adherence_reason'],
            'sl_points' => $slPoints,
            'sl_price' => $slPrice,
            'be_points' => $data['be_points'] ?? null,
            'be_price' => $bePrice,
            'be_size' => $data['be_size'] ?? null,
            'targets' => $targets,
            'notes' => $data['notes'] ?? null,
            'position_type' => PositionType::ORDER->value,
        ]);

        // Create order
        $order = $this->orderRepo->create([
            'position_id' => (int) $position['id'],
            'expires_at' => $data['expires_at'] ?? null,
            'status' => OrderStatus::PENDING->value,
        ]);

        // Log in status history
        $this->historyRepo->create([
            'entity_type' => EntityType::ORDER->value,
            'entity_id' => (int) $order['id'],
            'previous_status' => null,
            'new_status' => OrderStatus::PENDING->value,
            'user_id' => $userId,
            'trigger_type' => $trigger->value,
        ]);

        return $order;
    }

    public function list(int $userId, array $filters = []): array
    {
        $validFilters = [];

        if (!empty($filters['account_ids']) && is_array($filters['account_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['account_ids']), fn($id) => $id > 0));
            if (!empty($ids)) {
                $validFilters['account_ids'] = array_unique($ids);
            }
        } elseif (!empty($filters['account_id'])) {
            $validFilters['account_id'] = (int) $filters['account_id'];
        }

        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $valid = [];
            foreach ($filters['statuses'] as $s) {
                if (is_string($s) && OrderStatus::tryFrom($s)) {
                    $valid[] = $s;
                }
            }
            if (!empty($valid)) {
                $validFilters['statuses'] = array_values(array_unique($valid));
            }
        } elseif (!empty($filters['status']) && OrderStatus::tryFrom($filters['status'])) {
            $validFilters['status'] = $filters['status'];
        }

        if (!empty($filters['direction']) && Direction::tryFrom($filters['direction'])) {
            $validFilters['direction'] = $filters['direction'];
        }

        // Plan adherence filter (docs/83): a PlanAdherence value or 'NONE'.
        if (!empty($filters['plan_adherence'])
            && ($filters['plan_adherence'] === 'NONE' || PlanAdherence::tryFrom($filters['plan_adherence']) !== null)) {
            $validFilters['plan_adherence'] = $filters['plan_adherence'];
        }

        if (!empty($filters['symbol'])) {
            $validFilters['symbol'] = $filters['symbol'];
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        $result = $this->orderRepo->findAllByUserId($userId, $validFilters, $perPage, $offset);
        $total = $result['total'];
        $totalPages = (int) ceil($total / $perPage);

        return [
            'data' => $result['items'],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    public function get(int $userId, int $orderId): array
    {
        $this->validateId($orderId);

        $order = $this->orderRepo->findById($orderId);

        if (!$order) {
            throw new NotFoundException('orders.error.not_found');
        }

        if ((int) $order['user_id'] !== $userId) {
            throw new ForbiddenException('orders.error.forbidden');
        }

        return $order;
    }

    public function cancel(int $userId, int $orderId): array
    {
        $order = $this->get($userId, $orderId);

        if ($order['status'] !== OrderStatus::PENDING->value) {
            throw new ValidationException('orders.error.not_pending', 'status');
        }

        $result = $this->orderRepo->updateStatus($orderId, OrderStatus::CANCELLED->value);

        $this->historyRepo->create([
            'entity_type' => EntityType::ORDER->value,
            'entity_id' => $orderId,
            'previous_status' => OrderStatus::PENDING->value,
            'new_status' => OrderStatus::CANCELLED->value,
            'user_id' => $userId,
            'trigger_type' => TriggerType::MANUAL->value,
        ]);

        return $result;
    }

    public function execute(int $userId, int $orderId): array
    {
        $order = $this->get($userId, $orderId);

        if ($order['status'] !== OrderStatus::PENDING->value) {
            throw new ValidationException('orders.error.not_pending', 'status');
        }

        // 1. Update order status to EXECUTED
        $result = $this->orderRepo->updateStatus($orderId, OrderStatus::EXECUTED->value);

        $this->historyRepo->create([
            'entity_type' => EntityType::ORDER->value,
            'entity_id' => $orderId,
            'previous_status' => OrderStatus::PENDING->value,
            'new_status' => OrderStatus::EXECUTED->value,
            'user_id' => $userId,
            'trigger_type' => TriggerType::MANUAL->value,
        ]);

        // 2. Change position type from ORDER to TRADE
        $positionId = (int) $order['position_id'];
        $this->positionRepo->update($positionId, [
            'position_type' => PositionType::TRADE->value,
        ]);

        // 3. Create Trade OPEN linked to this order's position
        $trade = $this->tradeRepo->create([
            'position_id' => $positionId,
            'source_order_id' => $orderId,
            'opened_at' => date('Y-m-d H:i:s'),
            'remaining_size' => (float) $order['size'],
            'status' => TradeStatus::OPEN->value,
        ]);

        // 4. Log trade creation in status history
        $this->historyRepo->create([
            'entity_type' => EntityType::TRADE->value,
            'entity_id' => (int) $trade['id'],
            'previous_status' => null,
            'new_status' => TradeStatus::OPEN->value,
            'user_id' => $userId,
            'trigger_type' => TriggerType::MANUAL->value,
        ]);

        // Return order with trade info
        $result['trade_id'] = (int) $trade['id'];

        return $result;
    }

    public function delete(int $userId, int $orderId): void
    {
        $order = $this->get($userId, $orderId);

        // Delete the position (CASCADE will delete the order)
        $this->positionRepo->delete((int) $order['position_id']);
    }

    /** Normalize an incoming plan_id (string/int/empty/0) to ?int. */
    private function normalizePlanId(mixed $planId): ?int
    {
        if ($planId === null || $planId === '' || (int) $planId <= 0) {
            return null;
        }
        return (int) $planId;
    }

    /**
     * Evaluate an order against the plan it is placed under and freeze the
     * verdict (docs/83). The order carries it on its position, so the trade
     * inherits it on execute. Manual orders are never blocked — an unknown/
     * foreign/archived plan is the only hard error. Windows are evaluated at the
     * placement instant (now); risk % is best-effort (skipped if not computable).
     *
     * @return array{plan_id:?int, plan_adherence:?string, plan_adherence_reason:?string}
     */
    private function evaluatePlanAdherence(
        int $userId,
        int $accountId,
        ?int $planId,
        string $direction,
        string $symbol,
        float $entryPrice,
        float $size,
        float $slPoints
    ): array {
        if ($planId === null) {
            return ['plan_id' => null, 'plan_adherence' => null, 'plan_adherence_reason' => null];
        }
        if ($this->planRepo === null || $this->planEvaluator === null) {
            return ['plan_id' => $planId, 'plan_adherence' => null, 'plan_adherence_reason' => null];
        }
        if (!$this->planRepo->isOwnedAndActive($userId, $planId)) {
            throw new ValidationException('orders.error.invalid_plan', 'plan_id');
        }
        $plan = $this->planRepo->findByIdAssembled($planId);
        if ($plan === null) {
            throw new ValidationException('orders.error.invalid_plan', 'plan_id');
        }

        $riskPercent = $this->riskCalculator?->computePercent($userId, $accountId, $symbol, $size, $slPoints);

        try {
            $now = new DateTimeImmutable('now');
        } catch (Throwable) {
            $now = new DateTimeImmutable();
        }

        $reason = $this->planEvaluator->evaluate($plan, $direction, $entryPrice, $riskPercent, $now);
        return [
            'plan_id' => $planId,
            'plan_adherence' => $reason === null ? PlanAdherence::IN_PLAN->value : PlanAdherence::OUT_OF_PLAN->value,
            'plan_adherence_reason' => $reason,
        ];
    }

    private function validatePositionFields(array $data): void
    {
        // Required fields
        $this->validateRequired($data, 'direction', 'orders.error.field_required');
        $this->validateRequired($data, 'symbol', 'orders.error.field_required');
        $this->validateRequired($data, 'entry_price', 'orders.error.field_required');
        $this->validateRequired($data, 'size', 'orders.error.field_required');
        $this->validateRequired($data, 'setup', 'orders.error.field_required');
        $this->validateRequired($data, 'sl_points', 'orders.error.field_required');

        // Direction must be valid enum
        if (!Direction::tryFrom($data['direction'])) {
            throw new ValidationException('orders.error.invalid_direction', 'direction');
        }

        // Symbol: non-empty, max 50
        if (empty($data['symbol']) || mb_strlen($data['symbol']) > 50) {
            throw new ValidationException('orders.error.invalid_symbol', 'symbol');
        }

        // Entry price > 0
        if ((float) $data['entry_price'] <= 0) {
            throw new ValidationException('orders.error.invalid_price', 'entry_price');
        }

        // Size > 0
        if ((float) $data['size'] <= 0) {
            throw new ValidationException('orders.error.invalid_size', 'size');
        }

        // Setup: must be non-empty array of strings (max 20)
        if (empty($data['setup']) || !is_array($data['setup']) || count($data['setup']) === 0 || count($data['setup']) > 20) {
            throw new ValidationException('orders.error.invalid_setup', 'setup');
        }
        foreach ($data['setup'] as $label) {
            if (!is_string($label) || mb_strlen(trim($label)) === 0 || mb_strlen($label) > 100) {
                throw new ValidationException('orders.error.invalid_setup', 'setup');
            }
        }

        // SL points > 0
        if ((float) $data['sl_points'] <= 0) {
            throw new ValidationException('orders.error.invalid_sl_points', 'sl_points');
        }

        // Optional: be_points > 0
        if (isset($data['be_points']) && (float) $data['be_points'] <= 0) {
            throw new ValidationException('orders.error.invalid_be_points', 'be_points');
        }

        // Optional: be_size >= 0 (0 = BE without partial exit, null = BE not set)
        if (isset($data['be_size']) && (float) $data['be_size'] < 0) {
            throw new ValidationException('orders.error.invalid_be_size', 'be_size');
        }

        // Optional: notes max 10000
        if (isset($data['notes']) && mb_strlen($data['notes']) > 10000) {
            throw new ValidationException('orders.error.notes_too_long', 'notes');
        }

        // Optional: targets validation
        if (isset($data['targets'])) {
            $this->validateTargets($data['targets']);
        }
    }

    private function validateTargets(mixed $targets): void
    {
        if (is_string($targets)) {
            $decoded = json_decode($targets, true);
            if ($decoded === null && $targets !== 'null') {
                throw new ValidationException('orders.error.invalid_targets', 'targets');
            }
            $targets = $decoded;
        }

        if ($targets === null) {
            return;
        }

        if (!is_array($targets)) {
            throw new ValidationException('orders.error.invalid_targets', 'targets');
        }

        foreach ($targets as $target) {
            if (!is_array($target)) {
                throw new ValidationException('orders.error.invalid_targets', 'targets');
            }
            if (!isset($target['points']) || (float) $target['points'] <= 0) {
                throw new ValidationException('orders.error.invalid_target_points', 'targets');
            }
            if (!isset($target['size']) || (float) $target['size'] <= 0) {
                throw new ValidationException('orders.error.invalid_target_size', 'targets');
            }
        }
    }

    private function validateRequired(array $data, string $field, string $messageKey): void
    {
        if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
            throw new ValidationException($messageKey, $field);
        }
    }

    private function validateId(int $id): void
    {
        if ($id <= 0) {
            throw new ValidationException('error.invalid_id', 'id');
        }
    }

    private function calculateSlPrice(float $entryPrice, float $slPoints, string $direction): float
    {
        if ($direction === Direction::BUY->value) {
            return $entryPrice - $slPoints;
        }
        return $entryPrice + $slPoints;
    }

    private function calculateBePrice(float $entryPrice, float $bePoints, string $direction): float
    {
        if ($direction === Direction::BUY->value) {
            return $entryPrice + $bePoints;
        }
        return $entryPrice - $bePoints;
    }

    private function calculateTargetPrices(array $targets, float $entryPrice, string $direction): array
    {
        foreach ($targets as &$target) {
            if (isset($target['points'])) {
                if ($direction === Direction::BUY->value) {
                    $target['price'] = $entryPrice + (float) $target['points'];
                } else {
                    $target['price'] = $entryPrice - (float) $target['points'];
                }
            }
        }

        return $targets;
    }
}
