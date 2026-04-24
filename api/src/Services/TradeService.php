<?php

namespace App\Services;

use App\Enums\Direction;
use App\Enums\EntityType;
use App\Enums\ExitType;
use App\Enums\PositionType;
use App\Enums\TradeStatus;
use App\Enums\TriggerType;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\PartialExitRepository;
use App\Repositories\PositionRepository;
use App\Repositories\SetupRepository;
use App\Repositories\StatusHistoryRepository;
use App\Repositories\TradeRepository;

class TradeService
{
    private TradeRepository $tradeRepo;
    private PartialExitRepository $partialExitRepo;
    private PositionRepository $positionRepo;
    private AccountRepository $accountRepo;
    private StatusHistoryRepository $historyRepo;
    private ?SetupRepository $setupRepo;
    private ?CustomFieldService $customFieldService;

    public function __construct(
        TradeRepository $tradeRepo,
        PartialExitRepository $partialExitRepo,
        PositionRepository $positionRepo,
        AccountRepository $accountRepo,
        StatusHistoryRepository $historyRepo,
        ?SetupRepository $setupRepo = null,
        ?CustomFieldService $customFieldService = null
    ) {
        $this->tradeRepo = $tradeRepo;
        $this->partialExitRepo = $partialExitRepo;
        $this->positionRepo = $positionRepo;
        $this->accountRepo = $accountRepo;
        $this->historyRepo = $historyRepo;
        $this->setupRepo = $setupRepo;
        $this->customFieldService = $customFieldService;
    }

    public function create(int $userId, array $data): array
    {
        // Validate account ownership
        $this->validateRequired($data, 'account_id', 'trades.error.field_required');
        $accountId = (int) $data['account_id'];
        $this->validateId($accountId);
        $account = $this->accountRepo->findById($accountId);
        if (!$account) {
            throw new NotFoundException('accounts.error.not_found');
        }
        if ((int) $account['user_id'] !== $userId) {
            throw new ForbiddenException('trades.error.account_forbidden');
        }

        // Validate position fields
        $this->validatePositionFields($data);

        // Validate opened_at
        $this->validateRequired($data, 'opened_at', 'trades.error.field_required');

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

        // Auto-create unknown setups in dictionary
        if ($this->setupRepo) {
            $this->setupRepo->ensureExist($userId, $data['setup']);
        }

        // Create position
        $size = (float) $data['size'];
        $position = $this->positionRepo->create([
            'user_id' => $userId,
            'account_id' => $accountId,
            'direction' => $direction,
            'symbol' => $data['symbol'],
            'entry_price' => $entryPrice,
            'size' => $size,
            'setup' => json_encode($data['setup']),
            'sl_points' => $slPoints,
            'sl_price' => $slPrice,
            'be_points' => $data['be_points'] ?? null,
            'be_price' => $bePrice,
            'be_size' => $data['be_size'] ?? null,
            'targets' => $targets,
            'notes' => $data['notes'] ?? null,
            'position_type' => PositionType::TRADE->value,
        ]);

        // Create trade
        $trade = $this->tradeRepo->create([
            'position_id' => (int) $position['id'],
            'opened_at' => $data['opened_at'],
            'remaining_size' => $size,
            'status' => TradeStatus::OPEN->value,
        ]);

        // Log status history
        $this->historyRepo->create([
            'entity_type' => EntityType::TRADE->value,
            'entity_id' => (int) $trade['id'],
            'previous_status' => null,
            'new_status' => TradeStatus::OPEN->value,
            'user_id' => $userId,
            'trigger_type' => TriggerType::MANUAL->value,
        ]);

        // Save custom field values
        if ($this->customFieldService && !empty($data['custom_fields'])) {
            $this->customFieldService->validateAndSaveValues($userId, (int) $trade['id'], $data['custom_fields']);
            $trade['custom_fields'] = $this->customFieldService->findByTradeId((int) $trade['id']);
        } else {
            $trade['custom_fields'] = [];
        }

        return $trade;
    }

    public function list(int $userId, array $filters = []): array
    {
        $validFilters = [];

        if (!empty($filters['account_id'])) {
            $validFilters['account_id'] = (int) $filters['account_id'];
        }

        if (!empty($filters['status']) && TradeStatus::tryFrom($filters['status'])) {
            $validFilters['status'] = $filters['status'];
        }

        if (!empty($filters['direction']) && Direction::tryFrom($filters['direction'])) {
            $validFilters['direction'] = $filters['direction'];
        }

        if (!empty($filters['symbol'])) {
            $validFilters['symbol'] = $filters['symbol'];
        }

        if (!empty($filters['custom_filter']) && is_array($filters['custom_filter'])) {
            $cf = $filters['custom_filter'];
            if (!empty($cf['field_id']) && isset($cf['value'])) {
                $validFilters['custom_filter'] = [
                    'field_id' => (int) $cf['field_id'],
                    'value' => $cf['value'],
                ];
            }
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        $result = $this->tradeRepo->findAllByUserId($userId, $validFilters, $perPage, $offset);
        $total = $result['total'];
        $totalPages = (int) ceil($total / $perPage);

        $items = $result['items'];
        foreach ($items as &$item) {
            $item['partial_exits'] = $this->partialExitRepo->findByTradeId((int) $item['id']);
        }
        unset($item);

        // Batch load custom field values
        if ($this->customFieldService && !empty($items)) {
            $tradeIds = array_map(fn($item) => (int) $item['id'], $items);
            $customFieldsByTrade = $this->customFieldService->findByTradeIds($tradeIds);
            foreach ($items as &$item) {
                $item['custom_fields'] = $customFieldsByTrade[(int) $item['id']] ?? [];
            }
            unset($item);
        } else {
            foreach ($items as &$item) {
                $item['custom_fields'] = [];
            }
            unset($item);
        }

        return [
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    public function get(int $userId, int $tradeId): array
    {
        $this->validateId($tradeId);

        $trade = $this->tradeRepo->findById($tradeId);

        if (!$trade) {
            throw new NotFoundException('trades.error.not_found');
        }

        if ((int) $trade['user_id'] !== $userId) {
            throw new ForbiddenException('trades.error.forbidden');
        }

        $trade['partial_exits'] = $this->partialExitRepo->findByTradeId($tradeId);

        if ($this->customFieldService) {
            $trade['custom_fields'] = $this->customFieldService->findByTradeId($tradeId);
        } else {
            $trade['custom_fields'] = [];
        }

        return $trade;
    }

    public function close(int $userId, int $tradeId, array $data): array
    {
        $this->validateId($tradeId);

        $trade = $this->tradeRepo->findById($tradeId);

        if (!$trade) {
            throw new NotFoundException('trades.error.not_found');
        }

        if ((int) $trade['user_id'] !== $userId) {
            throw new ForbiddenException('trades.error.forbidden');
        }

        if ($trade['status'] === TradeStatus::CLOSED->value) {
            throw new ValidationException('trades.error.already_closed', 'status');
        }

        // Validate exit fields
        $this->validateRequired($data, 'exit_price', 'trades.error.field_required');
        $this->validateRequired($data, 'exit_size', 'trades.error.field_required');
        $this->validateRequired($data, 'exit_type', 'trades.error.field_required');

        $exitPrice = (float) $data['exit_price'];
        $exitSize = (float) $data['exit_size'];
        $exitTypeValue = $data['exit_type'];

        if ($exitPrice <= 0) {
            throw new ValidationException('trades.error.invalid_exit_price', 'exit_price');
        }

        if ($exitSize <= 0) {
            throw new ValidationException('trades.error.invalid_exit_size', 'exit_size');
        }

        $remainingSize = (float) $trade['remaining_size'];
        if ($exitSize > $remainingSize) {
            throw new ValidationException('trades.error.invalid_exit_size', 'exit_size');
        }

        if (!ExitType::tryFrom($exitTypeValue)) {
            throw new ValidationException('trades.error.invalid_exit_type', 'exit_type');
        }

        // Calculate partial PnL
        $entryPrice = (float) $trade['entry_price'];
        $direction = $trade['direction'];
        $directionMultiplier = $direction === Direction::BUY->value ? 1 : -1;
        $partialPnl = ($exitPrice - $entryPrice) * $exitSize * $directionMultiplier;

        // Create partial exit
        $exitedAt = $data['exited_at'] ?? date('Y-m-d H:i:s');
        $this->partialExitRepo->create([
            'trade_id' => $tradeId,
            'exited_at' => $exitedAt,
            'exit_price' => $exitPrice,
            'size' => $exitSize,
            'exit_type' => $exitTypeValue,
            'target_id' => $data['target_id'] ?? null,
            'pnl' => round($partialPnl, 2),
        ]);

        // Calculate new remaining size
        $newRemainingSize = $remainingSize - $exitSize;

        // Get all exits for avg calculations
        $allExits = $this->partialExitRepo->findByTradeId($tradeId);

        // Calculate avg exit price (weighted average)
        $avgExitPrice = $this->calculateAvgExitPrice($allExits);

        $previousStatus = $trade['status'];
        $updateData = [
            'remaining_size' => $newRemainingSize,
            'avg_exit_price' => $avgExitPrice,
        ];

        // Check if fully closed
        if (abs($newRemainingSize) < 0.0001) {
            $metrics = $this->calculateFinalMetrics($trade, $allExits, $exitedAt);
            $updateData['status'] = TradeStatus::CLOSED->value;
            $updateData['exit_type'] = $exitTypeValue;
            $updateData['closed_at'] = $exitedAt;
            $updateData['pnl'] = $metrics['pnl'];
            $updateData['pnl_percent'] = $metrics['pnl_percent'];
            $updateData['risk_reward'] = $metrics['risk_reward'];
            $updateData['duration_minutes'] = $metrics['duration_minutes'];
        } else {
            // Mark as SECURED if first exit or BE reached
            if ($previousStatus === TradeStatus::OPEN->value) {
                $updateData['status'] = TradeStatus::SECURED->value;
            }
        }

        $updated = $this->tradeRepo->update($tradeId, $updateData);

        // Log status history if changed
        $newStatus = $updateData['status'] ?? $previousStatus;
        if ($newStatus !== $previousStatus) {
            $this->historyRepo->create([
                'entity_type' => EntityType::TRADE->value,
                'entity_id' => $tradeId,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'user_id' => $userId,
                'trigger_type' => TriggerType::MANUAL->value,
            ]);
        }

        $updated['partial_exits'] = $allExits;

        return $updated;
    }

    public function markBeReached(int $userId, int $tradeId): array
    {
        $this->validateId($tradeId);

        $trade = $this->tradeRepo->findById($tradeId);

        if (!$trade) {
            throw new NotFoundException('trades.error.not_found');
        }

        if ((int) $trade['user_id'] !== $userId) {
            throw new ForbiddenException('trades.error.forbidden');
        }

        if ($trade['status'] === TradeStatus::CLOSED->value) {
            throw new ValidationException('trades.error.already_closed', 'status');
        }

        $previousStatus = $trade['status'];
        $updateData = ['be_reached' => 1];

        if ($previousStatus === TradeStatus::OPEN->value) {
            $updateData['status'] = TradeStatus::SECURED->value;
        }

        $updated = $this->tradeRepo->update($tradeId, $updateData);

        if (isset($updateData['status']) && $updateData['status'] !== $previousStatus) {
            $this->historyRepo->create([
                'entity_type' => EntityType::TRADE->value,
                'entity_id' => $tradeId,
                'previous_status' => $previousStatus,
                'new_status' => $updateData['status'],
                'user_id' => $userId,
                'trigger_type' => TriggerType::MANUAL->value,
            ]);
        }

        $updated['partial_exits'] = $this->partialExitRepo->findByTradeId($tradeId);

        return $updated;
    }

    public function delete(int $userId, int $tradeId): void
    {
        $this->validateId($tradeId);

        $trade = $this->tradeRepo->findById($tradeId);

        if (!$trade) {
            throw new NotFoundException('trades.error.not_found');
        }

        if ((int) $trade['user_id'] !== $userId) {
            throw new ForbiddenException('trades.error.forbidden');
        }

        // Delete the position (CASCADE will delete the trade + partial_exits)
        $this->positionRepo->delete((int) $trade['position_id']);
    }

    private function validatePositionFields(array $data): void
    {
        $this->validateRequired($data, 'direction', 'trades.error.field_required');
        $this->validateRequired($data, 'symbol', 'trades.error.field_required');
        $this->validateRequired($data, 'entry_price', 'trades.error.field_required');
        $this->validateRequired($data, 'size', 'trades.error.field_required');
        $this->validateRequired($data, 'setup', 'trades.error.field_required');
        $this->validateRequired($data, 'sl_points', 'trades.error.field_required');

        if (!Direction::tryFrom($data['direction'])) {
            throw new ValidationException('trades.error.invalid_direction', 'direction');
        }

        if (empty($data['symbol']) || mb_strlen($data['symbol']) > 50) {
            throw new ValidationException('trades.error.invalid_symbol', 'symbol');
        }

        if ((float) $data['entry_price'] <= 0) {
            throw new ValidationException('trades.error.invalid_price', 'entry_price');
        }

        if ((float) $data['size'] <= 0) {
            throw new ValidationException('trades.error.invalid_size', 'size');
        }

        if (empty($data['setup']) || !is_array($data['setup']) || count($data['setup']) === 0 || count($data['setup']) > 20) {
            throw new ValidationException('trades.error.invalid_setup', 'setup');
        }
        foreach ($data['setup'] as $label) {
            if (!is_string($label) || mb_strlen(trim($label)) === 0 || mb_strlen($label) > 100) {
                throw new ValidationException('trades.error.invalid_setup', 'setup');
            }
        }

        if ((float) $data['sl_points'] <= 0) {
            throw new ValidationException('trades.error.invalid_sl_points', 'sl_points');
        }

        if (isset($data['be_points']) && (float) $data['be_points'] <= 0) {
            throw new ValidationException('trades.error.invalid_be_points', 'be_points');
        }

        if (isset($data['be_size']) && (float) $data['be_size'] < 0) {
            throw new ValidationException('trades.error.invalid_be_size', 'be_size');
        }

        if (isset($data['notes']) && mb_strlen($data['notes']) > 10000) {
            throw new ValidationException('trades.error.notes_too_long', 'notes');
        }

        if (isset($data['targets'])) {
            $this->validateTargets($data['targets']);
        }
    }

    private function validateTargets(mixed $targets): void
    {
        if (is_string($targets)) {
            $decoded = json_decode($targets, true);
            if ($decoded === null && $targets !== 'null') {
                throw new ValidationException('trades.error.invalid_targets', 'targets');
            }
            $targets = $decoded;
        }

        if ($targets === null) {
            return;
        }

        if (!is_array($targets)) {
            throw new ValidationException('trades.error.invalid_targets', 'targets');
        }

        foreach ($targets as $target) {
            if (!is_array($target)) {
                throw new ValidationException('trades.error.invalid_targets', 'targets');
            }
            if (!isset($target['points']) || (float) $target['points'] <= 0) {
                throw new ValidationException('trades.error.invalid_target_points', 'targets');
            }
            if (!isset($target['size']) || (float) $target['size'] <= 0) {
                throw new ValidationException('trades.error.invalid_target_size', 'targets');
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

    private function calculateAvgExitPrice(array $exits): float
    {
        $totalWeighted = 0;
        $totalSize = 0;

        foreach ($exits as $exit) {
            $totalWeighted += (float) $exit['exit_price'] * (float) $exit['size'];
            $totalSize += (float) $exit['size'];
        }

        return $totalSize > 0 ? round($totalWeighted / $totalSize, 5) : 0;
    }

    private function calculateFinalMetrics(array $trade, array $exits, string $closedAt): array
    {
        $totalPnl = 0;
        foreach ($exits as $exit) {
            $totalPnl += (float) $exit['pnl'];
        }

        $entrySize = (float) $trade['size'];
        $slPoints = (float) $trade['sl_points'];
        $riskAmount = $entrySize * $slPoints;
        $riskReward = $riskAmount > 0 ? round($totalPnl / $riskAmount, 4) : 0;

        // PnL percent based on entry value
        $entryPrice = (float) $trade['entry_price'];
        $entryValue = $entryPrice * $entrySize;
        $pnlPercent = $entryValue > 0 ? round(($totalPnl / $entryValue) * 100, 4) : 0;

        // Duration in minutes
        $openedAt = strtotime($trade['opened_at']);
        $closedAtTime = strtotime($closedAt);
        $durationMinutes = $closedAtTime > $openedAt ? (int) round(($closedAtTime - $openedAt) / 60) : 0;

        return [
            'pnl' => round($totalPnl, 2),
            'pnl_percent' => $pnlPercent,
            'risk_reward' => $riskReward,
            'duration_minutes' => $durationMinutes,
        ];
    }
}
