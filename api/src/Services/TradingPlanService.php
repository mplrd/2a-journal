<?php

namespace App\Services;

use App\Enums\Direction;
use App\Enums\PlanStatus;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\SymbolRepository;
use App\Repositories\TradingPlanRepository;
use DateTimeZone;
use Throwable;

/**
 * Business logic for trading plans (docs/83-trading-plans.md). A plan is a
 * reusable, account-agnostic decision frame with four optional filters. Zones
 * and windows are validated, normalized, then replaced in bulk on write.
 */
class TradingPlanService
{
    public const MAX_PER_USER = 50;
    private const MAX_ZONES = 50;
    private const MAX_WINDOWS = 50;
    /** Ce que DECIMAL(6,3) peut stocker — les deux plafonds de risque. */
    private const MAX_RISK_PERCENT = 999.999;

    public function __construct(
        private TradingPlanRepository $repo,
        private SymbolRepository $symbolRepo,
    ) {}

    /** @return array<int,array> assembled active plans of the user */
    public function listForUser(int $userId): array
    {
        return $this->repo->findAllByUserId($userId);
    }

    public function getForUser(int $userId, int $planId): array
    {
        return $this->findOwnedPlan($userId, $planId);
    }

    public function create(int $userId, array $data): array
    {
        $clean = $this->validate($userId, $data);

        if (count($this->repo->findAllByUserId($userId)) >= self::MAX_PER_USER) {
            throw new ValidationException('plan.error.too_many', 'name');
        }

        $plan = $this->repo->create([
            'user_id' => $userId,
            'name' => $clean['name'],
            'symbol' => $clean['symbol'],
            'allowed_direction' => $clean['allowed_direction'],
            'timezone' => $clean['timezone'],
            'max_risk_percent' => $clean['max_risk_percent'],
            'max_plan_risk_percent' => $clean['max_plan_risk_percent'],
        ]);
        $planId = (int) $plan['id'];

        $this->repo->replaceZones($planId, $clean['zones']);
        $this->repo->replaceWindows($planId, $clean['windows']);

        return $this->repo->findByIdAssembled($planId);
    }

    public function update(int $userId, int $planId, array $data): array
    {
        $this->findOwnedPlan($userId, $planId);
        $clean = $this->validate($userId, $data);

        $this->repo->update($planId, [
            'name' => $clean['name'],
            'symbol' => $clean['symbol'],
            'allowed_direction' => $clean['allowed_direction'],
            'timezone' => $clean['timezone'],
            'max_risk_percent' => $clean['max_risk_percent'],
            'max_plan_risk_percent' => $clean['max_plan_risk_percent'],
        ]);
        $this->repo->replaceZones($planId, $clean['zones']);
        $this->repo->replaceWindows($planId, $clean['windows']);

        return $this->repo->findByIdAssembled($planId);
    }

    /** Soft-delete. Refused while an active robot still references the plan. */
    public function archive(int $userId, int $planId): void
    {
        $this->findOwnedPlan($userId, $planId);

        if ($this->repo->countActiveRobotsUsingPlan($planId) > 0) {
            throw new ValidationException('plan.error.in_use', 'id');
        }
        $this->repo->updateStatus($planId, PlanStatus::ARCHIVED->value);
    }

    // ── Validation / normalization ────────────────────────────────

    /** @return array{name:string,symbol:?string,allowed_direction:?string,timezone:?string,max_risk_percent:?float,max_plan_risk_percent:?float,zones:array,windows:array} */
    private function validate(int $userId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new ValidationException('plan.error.invalid_name', 'name');
        }

        // Instrument ciblé, optionnel (NULL = tous). Il doit faire partie des
        // actifs de l'utilisateur : une faute de frappe ferait autrement rejeter
        // la totalité des signaux, sans que rien ne le signale. On stocke la
        // forme canonique de l'actif pour que la comparaison au signal soit
        // toujours faite sur la même écriture.
        $symbol = $this->nullableString($data['symbol'] ?? null);
        if ($symbol !== null) {
            $asset = $this->symbolRepo->findByUserAndCode($userId, $symbol);
            if ($asset === null) {
                throw new ValidationException('plan.error.invalid_symbol', 'symbol');
            }
            $symbol = (string) $asset['code'];
        }

        $allowedDirection = $this->nullableString($data['allowed_direction'] ?? null);
        if ($allowedDirection !== null && Direction::tryFrom($allowedDirection) === null) {
            throw new ValidationException('plan.error.invalid_direction', 'allowed_direction');
        }

        $timezone = $this->nullableString($data['timezone'] ?? null);
        if ($timezone !== null && !$this->isValidTimezone($timezone)) {
            throw new ValidationException('plan.error.invalid_timezone', 'timezone');
        }

        // Les deux plafonds tiennent dans un DECIMAL(6,3). Sans borne haute, une
        // valeur au-delà passe la validation et casse à l'écriture : sous le
        // sql_mode strict de la prod, c'est une 500 là où l'utilisateur mérite
        // un message de champ.
        $maxRisk = $this->nullableFloat($data['max_risk_percent'] ?? null);
        if ($maxRisk !== null && ($maxRisk <= 0 || $maxRisk > self::MAX_RISK_PERCENT)) {
            throw new ValidationException('plan.error.invalid_risk', 'max_risk_percent');
        }

        // Plafond du risque cumulé des positions encore exposées sous ce plan.
        // Zéro refuserait tout signal sans que rien à l'écran ne l'explique.
        $maxPlanRisk = $this->nullableFloat($data['max_plan_risk_percent'] ?? null);
        if ($maxPlanRisk !== null && ($maxPlanRisk <= 0 || $maxPlanRisk > self::MAX_RISK_PERCENT)) {
            throw new ValidationException('plan.error.invalid_plan_risk', 'max_plan_risk_percent');
        }

        $zones = $this->validateZones($data['zones'] ?? []);
        $windows = $this->validateWindows($data['windows'] ?? [], $timezone);

        return [
            'name' => $name,
            'symbol' => $symbol,
            'allowed_direction' => $allowedDirection,
            'timezone' => $timezone,
            'max_risk_percent' => $maxRisk,
            'max_plan_risk_percent' => $maxPlanRisk,
            'zones' => $zones,
            'windows' => $windows,
        ];
    }

    private function validateZones(mixed $zones): array
    {
        if (!is_array($zones)) {
            throw new ValidationException('plan.error.invalid_zone', 'zones');
        }
        if (count($zones) > self::MAX_ZONES) {
            throw new ValidationException('plan.error.too_many_zones', 'zones');
        }

        $clean = [];
        foreach ($zones as $zone) {
            $direction = is_array($zone) ? ($zone['direction'] ?? null) : null;
            if (!is_string($direction) || Direction::tryFrom($direction) === null) {
                throw new ValidationException('plan.error.invalid_zone', 'zones');
            }
            $low = $this->nullableFloat($zone['low_price'] ?? null);
            $high = $this->nullableFloat($zone['high_price'] ?? null);
            if ($low === null || $high === null || $low <= 0 || $high <= 0) {
                throw new ValidationException('plan.error.invalid_zone', 'zones');
            }
            $clean[] = [
                'direction' => $direction,
                'low_price' => min($low, $high),
                'high_price' => max($low, $high),
            ];
        }
        return $clean;
    }

    private function validateWindows(mixed $windows, ?string $timezone): array
    {
        if (!is_array($windows)) {
            throw new ValidationException('plan.error.invalid_window', 'windows');
        }
        if ($windows !== [] && $timezone === null) {
            throw new ValidationException('plan.error.timezone_required', 'timezone');
        }
        if (count($windows) > self::MAX_WINDOWS) {
            throw new ValidationException('plan.error.too_many_windows', 'windows');
        }

        $clean = [];
        foreach ($windows as $window) {
            if (!is_array($window)) {
                throw new ValidationException('plan.error.invalid_window', 'windows');
            }
            $mask = (int) ($window['days_mask'] ?? 0);
            if ($mask < 1 || $mask > 0b1111111) {
                throw new ValidationException('plan.error.invalid_window_days', 'windows');
            }
            $start = $this->normalizeTime($window['start_time'] ?? null);
            $end = $this->normalizeTime($window['end_time'] ?? null);
            if ($start === null || $end === null || $this->toSeconds($start) >= $this->toSeconds($end)) {
                throw new ValidationException('plan.error.invalid_window_time', 'windows');
            }
            $clean[] = ['days_mask' => $mask, 'start_time' => $start, 'end_time' => $end];
        }
        return $clean;
    }

    private function findOwnedPlan(int $userId, int $planId): array
    {
        $plan = $this->repo->findByIdAssembled($planId);
        if ($plan === null || $plan['status'] === PlanStatus::ARCHIVED->value) {
            throw new NotFoundException('plan.error.not_found');
        }
        if ((int) $plan['user_id'] !== $userId) {
            throw new ForbiddenException('plan.error.forbidden');
        }
        return $plan;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (float) $value : null;
    }

    private function isValidTimezone(string $tz): bool
    {
        try {
            new DateTimeZone($tz);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Accept "H:i" or "H:i:s"; return "HH:MM:SS" or null if malformed. */
    private function normalizeTime(mixed $time): ?string
    {
        if (!is_string($time) || !preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($time), $m)) {
            return null;
        }
        [$h, $min, $s] = [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];
        if ($h > 23 || $min > 59 || $s > 59) {
            return null;
        }
        return sprintf('%02d:%02d:%02d', $h, $min, $s);
    }

    private function toSeconds(string $time): int
    {
        [$h, $m, $s] = array_pad(explode(':', $time), 3, '0');
        return (int) $h * 3600 + (int) $m * 60 + (int) $s;
    }
}
