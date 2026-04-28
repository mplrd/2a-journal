<?php

namespace App\Services;

use App\Enums\Direction;
use App\Enums\EntityType;
use App\Enums\PositionType;
use App\Enums\TriggerType;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\PositionRepository;
use App\Repositories\SetupRepository;
use App\Repositories\StatusHistoryRepository;

class PositionService
{
    private PositionRepository $positionRepo;
    private AccountRepository $accountRepo;
    private StatusHistoryRepository $historyRepo;
    private ?SetupRepository $setupRepo;
    private ?PlatformSettingsService $platformSettings;

    public function __construct(
        PositionRepository $positionRepo,
        AccountRepository $accountRepo,
        StatusHistoryRepository $historyRepo,
        ?SetupRepository $setupRepo = null,
        ?PlatformSettingsService $platformSettings = null
    ) {
        $this->positionRepo = $positionRepo;
        $this->accountRepo = $accountRepo;
        $this->historyRepo = $historyRepo;
        $this->setupRepo = $setupRepo;
        $this->platformSettings = $platformSettings;
    }

    public function listAggregated(int $userId, array $filters = []): array
    {
        $validFilters = [];

        if (!empty($filters['account_id'])) {
            $validFilters['account_id'] = (int) $filters['account_id'];
        }

        return $this->positionRepo->findAggregatedByUserId($userId, $validFilters);
    }

    public function list(int $userId, array $filters = []): array
    {
        $validFilters = [];

        if (!empty($filters['account_id'])) {
            $validFilters['account_id'] = (int) $filters['account_id'];
        }

        if (!empty($filters['position_type']) && PositionType::tryFrom($filters['position_type'])) {
            $validFilters['position_type'] = $filters['position_type'];
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

        $result = $this->positionRepo->findAllByUserId($userId, $validFilters, $perPage, $offset);
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

    public function get(int $userId, int $positionId): array
    {
        $this->validateId($positionId);

        $position = $this->positionRepo->findById($positionId);

        if (!$position) {
            throw new NotFoundException('positions.error.not_found');
        }

        if ((int) $position['user_id'] !== $userId) {
            throw new ForbiddenException('positions.error.forbidden');
        }

        return $position;
    }

    public function update(int $userId, int $positionId, array $data): array
    {
        $position = $this->get($userId, $positionId);

        $this->validateUpdate($data);

        // Use direction from data or existing position
        $direction = $data['direction'] ?? $position['direction'];
        $entryPrice = isset($data['entry_price']) ? (float) $data['entry_price'] : (float) $position['entry_price'];

        // Recalculate prices based on updated values
        $slPoints = isset($data['sl_points']) ? (float) $data['sl_points'] : (float) $position['sl_points'];
        $data['sl_price'] = $this->calculateSlPrice($entryPrice, $slPoints, $direction);

        if (isset($data['be_points']) || $position['be_points'] !== null) {
            $bePoints = isset($data['be_points']) ? (float) $data['be_points'] : (float) $position['be_points'];
            $data['be_price'] = $this->calculateBePrice($entryPrice, $bePoints, $direction);
        }

        if (isset($data['targets'])) {
            $targets = is_string($data['targets']) ? json_decode($data['targets'], true) : $data['targets'];
            if (is_array($targets)) {
                $targets = $this->calculateTargetPrices($targets, $entryPrice, $direction);
                $data['targets'] = json_encode($targets);
            }
        }

        // Setup: auto-create unknown setups and json_encode
        if (isset($data['setup']) && is_array($data['setup'])) {
            if ($this->setupRepo) {
                $this->setupRepo->ensureExist($userId, $data['setup']);
            }
            $data['setup'] = json_encode($data['setup']);
        }

        return $this->positionRepo->update((int) $position['id'], $data);
    }

    public function delete(int $userId, int $positionId): void
    {
        $this->get($userId, $positionId);

        $this->positionRepo->delete($positionId);
    }

    public function transfer(int $userId, int $positionId, array $data): array
    {
        // Server-side kill-switch. The UI hides the transfer button when
        // disabled; this enforces the same intent against direct API calls
        // so the flag is a real on/off, not just a UI nicety.
        if ($this->platformSettings !== null
            && $this->platformSettings->resolve('trade_transfer_enabled') !== true) {
            throw new ForbiddenException('positions.error.transfer_disabled');
        }

        $position = $this->get($userId, $positionId);

        if (empty($data['account_id'])) {
            throw new ValidationException('positions.error.field_required', 'account_id');
        }

        $this->validateId((int) $data['account_id']);

        $targetAccount = $this->accountRepo->findById((int) $data['account_id']);

        if (!$targetAccount) {
            throw new NotFoundException('accounts.error.not_found');
        }

        if ((int) $targetAccount['user_id'] !== $userId) {
            throw new ForbiddenException('positions.error.transfer_forbidden');
        }

        $oldAccountId = $position['account_id'];
        $result = $this->positionRepo->transfer((int) $position['id'], (int) $data['account_id']);

        // Log the transfer in status history
        $this->historyRepo->create([
            'entity_type' => EntityType::POSITION->value,
            'entity_id' => (int) $position['id'],
            'previous_status' => null,
            'new_status' => 'transferred',
            'user_id' => $userId,
            'trigger_type' => TriggerType::MANUAL->value,
            'details' => json_encode([
                'from_account_id' => (int) $oldAccountId,
                'to_account_id' => (int) $data['account_id'],
            ]),
        ]);

        return $result;
    }

    public function getHistory(int $userId, int $positionId): array
    {
        $this->get($userId, $positionId);

        return $this->historyRepo->findByEntity(EntityType::POSITION->value, $positionId);
    }

    private function validateUpdate(array $data): void
    {
        if (isset($data['entry_price']) && (float) $data['entry_price'] <= 0) {
            throw new ValidationException('positions.error.invalid_price', 'entry_price');
        }

        if (isset($data['size']) && (float) $data['size'] <= 0) {
            throw new ValidationException('positions.error.invalid_size', 'size');
        }

        if (isset($data['sl_points']) && (float) $data['sl_points'] <= 0) {
            throw new ValidationException('positions.error.invalid_sl_points', 'sl_points');
        }

        if (isset($data['be_points']) && (float) $data['be_points'] <= 0) {
            throw new ValidationException('positions.error.invalid_be_points', 'be_points');
        }

        if (isset($data['be_size']) && (float) $data['be_size'] < 0) {
            throw new ValidationException('positions.error.invalid_be_size', 'be_size');
        }

        if (isset($data['direction']) && !Direction::tryFrom($data['direction'])) {
            throw new ValidationException('positions.error.invalid_direction', 'direction');
        }

        if (array_key_exists('symbol', $data) && (empty($data['symbol']) || mb_strlen($data['symbol']) > 50)) {
            throw new ValidationException('positions.error.invalid_symbol', 'symbol');
        }

        if (array_key_exists('setup', $data)) {
            if (empty($data['setup']) || !is_array($data['setup']) || count($data['setup']) === 0 || count($data['setup']) > 20) {
                throw new ValidationException('positions.error.invalid_setup', 'setup');
            }
            foreach ($data['setup'] as $label) {
                if (!is_string($label) || mb_strlen(trim($label)) === 0 || mb_strlen($label) > 100) {
                    throw new ValidationException('positions.error.invalid_setup', 'setup');
                }
            }
        }

        if (isset($data['notes']) && mb_strlen($data['notes']) > 10000) {
            throw new ValidationException('positions.error.notes_too_long', 'notes');
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
                throw new ValidationException('positions.error.invalid_targets', 'targets');
            }
            $targets = $decoded;
        }

        if ($targets === null) {
            return;
        }

        if (!is_array($targets)) {
            throw new ValidationException('positions.error.invalid_targets', 'targets');
        }

        foreach ($targets as $target) {
            if (!is_array($target)) {
                throw new ValidationException('positions.error.invalid_targets', 'targets');
            }
            if (!isset($target['points']) || (float) $target['points'] <= 0) {
                throw new ValidationException('positions.error.invalid_target_points', 'targets');
            }
            if (!isset($target['size']) || (float) $target['size'] <= 0) {
                throw new ValidationException('positions.error.invalid_target_size', 'targets');
            }
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

    private function validateId(int $id): void
    {
        if ($id <= 0) {
            throw new ValidationException('error.invalid_id', 'id');
        }
    }
}
