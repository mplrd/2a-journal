<?php

namespace App\Repositories;

use App\Enums\PlanStatus;
use App\Enums\RobotStatus;
use PDO;

/**
 * Data access for trading plans and their children (docs/83-trading-plans.md).
 * Owns the robot_plans many-to-many link as well, so all plan-related SQL lives
 * in one place. Plans are assembled (zones + windows attached) on read.
 */
class TradingPlanRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO trading_plans (user_id, name, symbol, allowed_direction, timezone, max_risk_percent, max_plan_risk_percent)
             VALUES (:user_id, :name, :symbol, :allowed_direction, :timezone, :max_risk_percent, :max_plan_risk_percent)"
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'name' => $data['name'],
            'symbol' => $data['symbol'] ?? null,
            'allowed_direction' => $data['allowed_direction'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'max_risk_percent' => $data['max_risk_percent'] ?? null,
            'max_plan_risk_percent' => $data['max_plan_risk_percent'] ?? null,
        ]);

        return $this->findByIdAssembled((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM trading_plans WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Plan row with its zones[] and windows[] attached, or null. */
    public function findByIdAssembled(int $id): ?array
    {
        $plan = $this->findById($id);
        if ($plan === null) {
            return null;
        }
        $plan['zones'] = $this->findZonesByPlanId($id);
        $plan['windows'] = $this->findWindowsByPlanId($id);
        return $plan;
    }

    /** All non-archived plans of a user, newest first, each assembled. */
    public function findAllByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM trading_plans
             WHERE user_id = :user_id AND status <> :archived
             ORDER BY created_at DESC"
        );
        $stmt->execute(['user_id' => $userId, 'archived' => PlanStatus::ARCHIVED->value]);
        $plans = $stmt->fetchAll();

        foreach ($plans as &$plan) {
            $id = (int) $plan['id'];
            $plan['zones'] = $this->findZonesByPlanId($id);
            $plan['windows'] = $this->findWindowsByPlanId($id);
            $plan['robot_count'] = $this->countActiveRobotsUsingPlan($id);
        }
        return $plans;
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE trading_plans
             SET name = :name,
                 symbol = :symbol,
                 allowed_direction = :allowed_direction,
                 timezone = :timezone,
                 max_risk_percent = :max_risk_percent,
                 max_plan_risk_percent = :max_plan_risk_percent
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $id,
            'name' => $data['name'],
            'symbol' => $data['symbol'] ?? null,
            'allowed_direction' => $data['allowed_direction'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'max_risk_percent' => $data['max_risk_percent'] ?? null,
            'max_plan_risk_percent' => $data['max_plan_risk_percent'] ?? null,
        ]);
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->pdo->prepare("UPDATE trading_plans SET status = :s WHERE id = :id")
            ->execute(['s' => $status, 'id' => $id]);
    }

    /** Drop then re-insert all zones of a plan (bulk replace). */
    public function replaceZones(int $planId, array $zones): void
    {
        $this->pdo->prepare("DELETE FROM trading_plan_zones WHERE plan_id = :pid")
            ->execute(['pid' => $planId]);
        if ($zones === []) {
            return;
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO trading_plan_zones (plan_id, direction, low_price, high_price)
             VALUES (:pid, :direction, :low, :high)"
        );
        foreach ($zones as $zone) {
            $stmt->execute([
                'pid' => $planId,
                'direction' => $zone['direction'],
                'low' => $zone['low_price'],
                'high' => $zone['high_price'],
            ]);
        }
    }

    /** Drop then re-insert all windows of a plan (bulk replace). */
    public function replaceWindows(int $planId, array $windows): void
    {
        $this->pdo->prepare("DELETE FROM trading_plan_windows WHERE plan_id = :pid")
            ->execute(['pid' => $planId]);
        if ($windows === []) {
            return;
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO trading_plan_windows (plan_id, days_mask, start_time, end_time)
             VALUES (:pid, :days, :start, :end)"
        );
        foreach ($windows as $window) {
            $stmt->execute([
                'pid' => $planId,
                'days' => $window['days_mask'],
                'start' => $window['start_time'],
                'end' => $window['end_time'],
            ]);
        }
    }

    private function findZonesByPlanId(int $planId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, direction, low_price, high_price
             FROM trading_plan_zones WHERE plan_id = :pid ORDER BY id"
        );
        $stmt->execute(['pid' => $planId]);
        return $stmt->fetchAll();
    }

    private function findWindowsByPlanId(int $planId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, days_mask, start_time, end_time
             FROM trading_plan_windows WHERE plan_id = :pid ORDER BY id"
        );
        $stmt->execute(['pid' => $planId]);
        return $stmt->fetchAll();
    }

    /** Non-archived robots referencing a plan — gates archiving. */
    public function countActiveRobotsUsingPlan(int $planId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM robot_plans rp
             INNER JOIN robots r ON r.id = rp.robot_id
             WHERE rp.plan_id = :pid AND r.status <> :archived"
        );
        $stmt->execute(['pid' => $planId, 'archived' => RobotStatus::ARCHIVED->value]);
        return (int) $stmt->fetchColumn();
    }

    // ── robot_plans link ──────────────────────────────────────────

    /** Replace the full set of plans attached to a robot. */
    public function setRobotPlans(int $robotId, array $planIds): void
    {
        $this->pdo->prepare("DELETE FROM robot_plans WHERE robot_id = :rid")
            ->execute(['rid' => $robotId]);
        if ($planIds === []) {
            return;
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO robot_plans (robot_id, plan_id) VALUES (:rid, :pid)"
        );
        foreach (array_unique($planIds) as $planId) {
            $stmt->execute(['rid' => $robotId, 'pid' => $planId]);
        }
    }

    /** Plan ids attached to a robot (all, for edit prefill). */
    public function findPlanIdsForRobot(int $robotId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT plan_id FROM robot_plans WHERE robot_id = :rid ORDER BY plan_id"
        );
        $stmt->execute(['rid' => $robotId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** {id,name} of active plans attached to a robot (for display). */
    public function findActivePlansForRobot(int $robotId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.name
             FROM robot_plans rp
             INNER JOIN trading_plans p ON p.id = rp.plan_id
             WHERE rp.robot_id = :rid AND p.status = :active
             ORDER BY p.name"
        );
        $stmt->execute(['rid' => $robotId, 'active' => PlanStatus::ACTIVE->value]);
        return $stmt->fetchAll();
    }

    /** Assembled ACTIVE plans attached to a robot — used by the ingestion gate. */
    public function findAssembledActivePlansForRobot(int $robotId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT rp.plan_id
             FROM robot_plans rp
             INNER JOIN trading_plans p ON p.id = rp.plan_id
             WHERE rp.robot_id = :rid AND p.status = :active"
        );
        $stmt->execute(['rid' => $robotId, 'active' => PlanStatus::ACTIVE->value]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $plans = [];
        foreach ($ids as $id) {
            $assembled = $this->findByIdAssembled($id);
            if ($assembled !== null) {
                $plans[] = $assembled;
            }
        }
        return $plans;
    }

    /** True when the plan exists, is active, and belongs to the user. */
    public function isOwnedAndActive(int $userId, int $planId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM trading_plans
             WHERE id = :id AND user_id = :uid AND status = :active"
        );
        $stmt->execute(['id' => $planId, 'uid' => $userId, 'active' => PlanStatus::ACTIVE->value]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
