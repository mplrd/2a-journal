<?php

namespace App\Services;

use App\Enums\Direction;
use App\Enums\EntityType;
use App\Enums\OrderStatus;
use App\Enums\PositionType;
use App\Enums\TriggerType;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PositionRepository;
use App\Repositories\StatusHistoryRepository;

class OrderService
{
    private OrderRepository $orderRepo;
    private PositionRepository $positionRepo;
    private AccountRepository $accountRepo;
    private StatusHistoryRepository $historyRepo;

    public function __construct(
        OrderRepository $orderRepo,
        PositionRepository $positionRepo,
        AccountRepository $accountRepo,
        StatusHistoryRepository $historyRepo
    ) {
        $this->orderRepo = $orderRepo;
        $this->positionRepo = $positionRepo;
        $this->accountRepo = $accountRepo;
        $this->historyRepo = $historyRepo;
    }

    public function create(int $userId, array $data): array
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
                $targets = json_encode($targetsData);
            }
        }

        // Create position
        $position = $this->positionRepo->create([
            'user_id' => $userId,
            'account_id' => $accountId,
            'direction' => $direction,
            'symbol' => $data['symbol'],
            'entry_price' => $entryPrice,
            'size' => (float) $data['size'],
            'setup' => $data['setup'],
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
            'trigger_type' => TriggerType::MANUAL->value,
        ]);

        return $order;
    }

    public function list(int $userId, array $filters = []): array
    {
        $validFilters = [];

        if (!empty($filters['account_id'])) {
            $validFilters['account_id'] = (int) $filters['account_id'];
        }

        if (!empty($filters['status']) && OrderStatus::tryFrom($filters['status'])) {
            $validFilters['status'] = $filters['status'];
        }

        if (!empty($filters['direction']) && Direction::tryFrom($filters['direction'])) {
            $validFilters['direction'] = $filters['direction'];
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

        $result = $this->orderRepo->updateStatus($orderId, OrderStatus::EXECUTED->value);

        $this->historyRepo->create([
            'entity_type' => EntityType::ORDER->value,
            'entity_id' => $orderId,
            'previous_status' => OrderStatus::PENDING->value,
            'new_status' => OrderStatus::EXECUTED->value,
            'user_id' => $userId,
            'trigger_type' => TriggerType::MANUAL->value,
        ]);

        return $result;
    }

    public function delete(int $userId, int $orderId): void
    {
        $order = $this->get($userId, $orderId);

        // Delete the position (CASCADE will delete the order)
        $this->positionRepo->delete((int) $order['position_id']);
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

        // Setup: non-empty, max 255
        if (empty($data['setup']) || mb_strlen($data['setup']) > 255) {
            throw new ValidationException('orders.error.invalid_setup', 'setup');
        }

        // SL points > 0
        if ((float) $data['sl_points'] <= 0) {
            throw new ValidationException('orders.error.invalid_sl_points', 'sl_points');
        }

        // Optional: be_points > 0
        if (isset($data['be_points']) && (float) $data['be_points'] <= 0) {
            throw new ValidationException('orders.error.invalid_be_points', 'be_points');
        }

        // Optional: be_size > 0
        if (isset($data['be_size']) && (float) $data['be_size'] <= 0) {
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
