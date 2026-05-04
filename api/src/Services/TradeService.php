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
    private ?DrawdownService $drawdownService;

    public function __construct(
        TradeRepository $tradeRepo,
        PartialExitRepository $partialExitRepo,
        PositionRepository $positionRepo,
        AccountRepository $accountRepo,
        StatusHistoryRepository $historyRepo,
        ?SetupRepository $setupRepo = null,
        ?CustomFieldService $customFieldService = null,
        ?DrawdownService $drawdownService = null
    ) {
        $this->tradeRepo = $tradeRepo;
        $this->partialExitRepo = $partialExitRepo;
        $this->positionRepo = $positionRepo;
        $this->accountRepo = $accountRepo;
        $this->historyRepo = $historyRepo;
        $this->setupRepo = $setupRepo;
        $this->customFieldService = $customFieldService;
        $this->drawdownService = $drawdownService;
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

        if (!empty($filters['account_ids']) && is_array($filters['account_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['account_ids']), fn($id) => $id > 0));
            if (!empty($ids)) {
                $validFilters['account_ids'] = array_unique($ids);
            }
        } elseif (!empty($filters['account_id'])) {
            $validFilters['account_id'] = (int) $filters['account_id'];
        }

        // `statuses` is a list (from the multi-select filter); `status` (singular)
        // stays for backward compat. Each entry is whitelisted via the TradeStatus
        // enum, unknown or non-string entries are silently dropped rather than
        // erroring out — a malformed query param shouldn't 400 the whole page.
        $rawStatuses = [];
        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $rawStatuses = $filters['statuses'];
        } elseif (!empty($filters['status'])) {
            $rawStatuses = [$filters['status']];
        }
        $validStatuses = [];
        foreach ($rawStatuses as $s) {
            if (is_string($s) && TradeStatus::tryFrom($s)) {
                $validStatuses[] = $s;
            }
        }
        if (!empty($validStatuses)) {
            $validFilters['statuses'] = array_values(array_unique($validStatuses));
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

        // Realized metrics are computed on EVERY exit (partial or full) so the
        // running P&L is visible immediately for swing trades.
        $metrics = $this->calculateRealizedMetrics($trade, $allExits, $exitedAt);

        $previousStatus = $trade['status'];
        $updateData = [
            'remaining_size' => $newRemainingSize,
            'avg_exit_price' => $avgExitPrice,
            'pnl' => $metrics['pnl'],
            'pnl_percent' => $metrics['pnl_percent'],
            'risk_reward' => $metrics['risk_reward'],
        ];

        if (abs($newRemainingSize) < 0.0001) {
            // Full close — set terminal fields. duration is meaningful only here.
            $updateData['status'] = TradeStatus::CLOSED->value;
            $updateData['exit_type'] = $exitTypeValue;
            $updateData['closed_at'] = $exitedAt;
            $updateData['duration_minutes'] = $metrics['duration_minutes'];
        } elseif ($previousStatus === TradeStatus::OPEN->value && $exitTypeValue === ExitType::BE->value) {
            // Only a BE exit secures the trade — the SL has been moved to BE,
            // so the remainder is risk-free. A TP partial does not promote OPEN
            // to SECURED on its own (the SL is still on the original level on
            // the remaining position, the trade is not actually secured).
            $updateData['status'] = TradeStatus::SECURED->value;
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

        // Fire-and-forget DD-approach alert (E-08). Internal try/catch keeps
        // any email/dedup failure from breaking the trade-close response.
        if ($this->drawdownService !== null) {
            $this->drawdownService->checkAndNotifyForAccount((int) $trade['account_id'], $userId);
        }

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

        // Defensive recalc — no-op if no partials, idempotent otherwise.
        $this->recalcRealizedMetrics($tradeId);

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

    public function update(int $userId, int $tradeId, array $data): array
    {
        // Ownership + presence check
        $trade = $this->get($userId, $tradeId);

        $this->validatePartialPositionFields($data);

        if (array_key_exists('opened_at', $data)) {
            $this->validateDateTime($data['opened_at'], 'trades.error.invalid_opened_at', 'opened_at');
        }

        if (array_key_exists('closed_at', $data) && $data['closed_at'] !== null) {
            if ($trade['status'] !== TradeStatus::CLOSED->value) {
                throw new ValidationException('trades.error.closed_at_not_closed', 'closed_at');
            }
            $this->validateDateTime($data['closed_at'], 'trades.error.invalid_closed_at', 'closed_at');
        }

        // Recompute derived position prices when their inputs are touched.
        $direction = $data['direction'] ?? $trade['direction'];
        $entryPrice = isset($data['entry_price']) ? (float) $data['entry_price'] : (float) $trade['entry_price'];

        $positionUpdates = [];
        $positionFields = ['direction', 'symbol', 'entry_price', 'size', 'sl_points', 'be_points', 'be_size', 'notes', 'setup', 'targets'];
        foreach ($positionFields as $field) {
            if (array_key_exists($field, $data)) {
                $positionUpdates[$field] = $data[$field];
            }
        }

        // sl_price always tracks (entry_price, sl_points, direction); recompute
        // whenever any of the three is in scope.
        if (isset($positionUpdates['entry_price']) || isset($positionUpdates['sl_points']) || isset($positionUpdates['direction'])) {
            $slPoints = isset($data['sl_points']) ? (float) $data['sl_points'] : (float) $trade['sl_points'];
            $positionUpdates['sl_price'] = $this->calculateSlPrice($entryPrice, $slPoints, $direction);
        }

        if (array_key_exists('be_points', $data)) {
            if ($data['be_points'] !== null && $data['be_points'] !== '') {
                $positionUpdates['be_price'] = $this->calculateBePrice($entryPrice, (float) $data['be_points'], $direction);
            } else {
                $positionUpdates['be_price'] = null;
                $positionUpdates['be_size'] = null;
            }
        }

        if (array_key_exists('targets', $data)) {
            $targets = is_string($data['targets']) ? json_decode($data['targets'], true) : $data['targets'];
            if (is_array($targets)) {
                $targets = $this->calculateTargetPrices($targets, $entryPrice, $direction);
                $positionUpdates['targets'] = json_encode($targets);
            } elseif ($targets === null) {
                $positionUpdates['targets'] = null;
            }
        }

        if (isset($positionUpdates['setup']) && is_array($positionUpdates['setup'])) {
            if ($this->setupRepo) {
                $this->setupRepo->ensureExist($userId, $positionUpdates['setup']);
            }
            $positionUpdates['setup'] = json_encode($positionUpdates['setup']);
        }

        if (!empty($positionUpdates)) {
            $this->positionRepo->update((int) $trade['position_id'], $positionUpdates);
        }

        // Trade-level fields (opened_at, closed_at)
        $tradeUpdates = [];
        foreach (['opened_at', 'closed_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $tradeUpdates[$field] = $data[$field];
            }
        }
        if (!empty($tradeUpdates)) {
            $this->tradeRepo->update($tradeId, $tradeUpdates);
        }

        // Custom fields (whole-list replacement)
        if (array_key_exists('custom_fields', $data) && $this->customFieldService) {
            $this->customFieldService->validateAndSaveValues($userId, $tradeId, $data['custom_fields'] ?? []);
        }

        // Defensive recalc — important when entry_price / size / sl_points
        // changed via this update (pnl_percent and risk_reward depend on them).
        $this->recalcRealizedMetrics($tradeId);

        return $this->get($userId, $tradeId);
    }

    private function validatePartialPositionFields(array $data): void
    {
        if (isset($data['direction']) && !Direction::tryFrom($data['direction'])) {
            throw new ValidationException('trades.error.invalid_direction', 'direction');
        }
        if (isset($data['symbol']) && (empty($data['symbol']) || mb_strlen($data['symbol']) > 50)) {
            throw new ValidationException('trades.error.invalid_symbol', 'symbol');
        }
        if (isset($data['entry_price']) && (float) $data['entry_price'] <= 0) {
            throw new ValidationException('trades.error.invalid_price', 'entry_price');
        }
        if (isset($data['size']) && (float) $data['size'] <= 0) {
            throw new ValidationException('trades.error.invalid_size', 'size');
        }
        if (isset($data['setup'])) {
            if (!is_array($data['setup']) || count($data['setup']) === 0 || count($data['setup']) > 20) {
                throw new ValidationException('trades.error.invalid_setup', 'setup');
            }
            foreach ($data['setup'] as $label) {
                if (!is_string($label) || mb_strlen(trim($label)) === 0 || mb_strlen($label) > 100) {
                    throw new ValidationException('trades.error.invalid_setup', 'setup');
                }
            }
        }
        if (isset($data['sl_points']) && (float) $data['sl_points'] <= 0) {
            throw new ValidationException('trades.error.invalid_sl_points', 'sl_points');
        }
        if (isset($data['be_points']) && $data['be_points'] !== null && (float) $data['be_points'] <= 0) {
            throw new ValidationException('trades.error.invalid_be_points', 'be_points');
        }
        if (isset($data['be_size']) && $data['be_size'] !== null && (float) $data['be_size'] < 0) {
            throw new ValidationException('trades.error.invalid_be_size', 'be_size');
        }
        if (isset($data['notes']) && mb_strlen($data['notes']) > 10000) {
            throw new ValidationException('trades.error.notes_too_long', 'notes');
        }
        if (isset($data['targets'])) {
            $this->validateTargets($data['targets']);
        }
    }

    private function validateDateTime(string $value, string $messageKey, string $field): void
    {
        if (!is_string($value) || trim($value) === '') {
            throw new ValidationException($messageKey, $field);
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new ValidationException($messageKey, $field);
        }
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

    /**
     * Re-derives realized metrics for a trade from the ground up:
     * 1. Recomputes each partial_exit.pnl from the trade's current entry_price
     *    and direction (so an entry_price edit propagates to historical exits).
     * 2. Aggregates into trade.pnl / pnl_percent / risk_reward.
     *
     * No-op for trades with zero partial exits.
     *
     * Called from any flow that touches a trade (close, markBeReached, update),
     * so the realized P&L stays consistent across all entry-affecting edits.
     */
    private function recalcRealizedMetrics(int $tradeId): void
    {
        $trade = $this->tradeRepo->findById($tradeId);
        if (!$trade) {
            return;
        }
        $partials = $this->partialExitRepo->findByTradeId($tradeId);
        if (empty($partials)) {
            return;
        }

        $entryPrice = (float) $trade['entry_price'];
        $direction = $trade['direction'];
        $directionMultiplier = $direction === Direction::BUY->value ? 1 : -1;

        // 1. Recompute each partial's pnl using the current entry_price + direction.
        $totalPnl = 0;
        foreach ($partials as $partial) {
            $newPnl = ((float) $partial['exit_price'] - $entryPrice)
                * (float) $partial['size']
                * $directionMultiplier;
            $newPnl = round($newPnl, 2);
            if (abs($newPnl - (float) $partial['pnl']) > 0.001) {
                $this->partialExitRepo->updatePnl((int) $partial['id'], $newPnl);
            }
            $totalPnl += $newPnl;
        }

        // 2. Aggregate at the trade level.
        $entrySize = (float) $trade['size'];
        $slPoints = (float) $trade['sl_points'];
        $entryValue = $entryPrice * $entrySize;
        $riskAmount = $entrySize * $slPoints;

        $this->tradeRepo->update($tradeId, [
            'pnl' => round($totalPnl, 2),
            'pnl_percent' => $entryValue > 0 ? round($totalPnl / $entryValue * 100, 4) : 0,
            'risk_reward' => $riskAmount > 0 ? round($totalPnl / $riskAmount, 4) : null,
        ]);
    }

    /**
     * Aggregate realized metrics across all partial exits to date. Called on every
     * close() invocation: returns running P&L for partial exits, final P&L when
     * remaining_size is 0. The signature is identical regardless — only the caller
     * decides whether to also persist terminal fields (status, closed_at, …).
     *
     * `closedAt` is the current exit's timestamp; used solely for `duration_minutes`,
     * which the caller persists only when the trade is fully closed.
     */
    private function calculateRealizedMetrics(array $trade, array $exits, string $closedAt): array
    {
        $totalPnl = 0;
        foreach ($exits as $exit) {
            $totalPnl += (float) $exit['pnl'];
        }

        $entrySize = (float) $trade['size'];
        $slPoints = (float) $trade['sl_points'];
        $riskAmount = $entrySize * $slPoints;
        $riskReward = $riskAmount > 0 ? round($totalPnl / $riskAmount, 4) : null;

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
