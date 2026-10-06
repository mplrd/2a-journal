<?php

namespace App\Services\Export;

use App\Enums\CustomFieldType;
use App\Enums\PositionType;
use App\Repositories\CustomFieldDefinitionRepository;
use App\Repositories\CustomFieldValueRepository;
use App\Repositories\PartialExitRepository;
use App\Repositories\PositionRepository;
use App\Repositories\SetupRepository;
use App\Repositories\UserRepository;
use App\Services\AccountService;
use DateTimeImmutable;

/**
 * Builds the account export: the account, its trades and its unexecuted
 * orders, as fixed-column tables.
 *
 * A trade has any number of targets and exits, so the Trades sheet has one row
 * per leg: one per target (with the exit that hit it, if any), then one per
 * exit tied to no target (break-even, stop, manual close of the rest). The
 * trade's own columns repeat on each of its rows. Unexecuted orders get one
 * row per target the same way.
 *
 * Values are extracted as stored, never computed, and coded values are
 * translated. The sheets are plain rows of typed values (string, int, float,
 * DateTimeImmutable or null); AccountExportXlsxWriter turns them into bytes.
 */
class AccountExportService
{
    private const TRADE_COLUMNS = [
        'trade_number', 'status', 'order_created_at', 'opened_at', 'secured_at', 'closed_at', 'symbol',
        'direction', 'size', 'remaining_size', 'entry_price', 'sl_price', 'be_price', 'be_size',
        'target', 'target_price', 'target_size',
        'exit_at', 'exit_price', 'exit_size', 'exit_kind', 'exit_pnl',
        'avg_exit_price', 'exit_type', 'risk_reward', 'pnl', 'plan', 'plan_adherence', 'plan_adherence_reason',
        'setup_timeframe', 'setup_pattern', 'setup_context', 'setup_uncategorized', 'custom_fields', 'notes',
    ];

    private const ORDER_COLUMNS = [
        'order_number', 'status', 'placed_at', 'expires_at', 'symbol', 'direction', 'size', 'entry_price',
        'sl_price', 'be_price', 'be_size',
        'target', 'target_price', 'target_size',
        'plan', 'plan_adherence', 'plan_adherence_reason',
        'setup_timeframe', 'setup_pattern', 'setup_context', 'setup_uncategorized', 'notes',
    ];

    /** Setup category (setups.category) → export column. No category, or a tag gone from the catalogue: uncategorized. */
    private const SETUP_COLUMNS = [
        'timeframe' => 'setup_timeframe',
        'pattern' => 'setup_pattern',
        'context' => 'setup_context',
    ];

    public function __construct(
        private AccountService $accounts,
        private PositionRepository $positions,
        private PartialExitRepository $exits,
        private CustomFieldDefinitionRepository $fieldDefinitions,
        private CustomFieldValueRepository $fieldValues,
        private SetupRepository $setups,
        private UserRepository $users,
    ) {}

    /**
     * @return array{filename: string, sheets: list<array{title: string, header_rows: list<int>, rows: list<list<mixed>>}>}
     */
    public function build(int $userId, int $accountId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        // Ownership and soft delete are enforced here, before anything is read.
        $account = $this->accounts->get($userId, $accountId);
        $adjustments = $this->accounts->listAdjustments($userId, $accountId);
        $positions = $this->positions->findForAccountExport($userId, $accountId);
        $labels = AccountExportLabels::for($this->users->findById($userId)['locale'] ?? null);

        $trades = array_values(array_filter($positions, fn ($p) => $p['position_type'] === PositionType::TRADE->value));
        $orders = array_values(array_filter($positions, fn ($p) => $p['position_type'] === PositionType::ORDER->value));
        $tradeIds = array_map(fn ($t) => (int) $t['trade_id'], $trades);
        $exitsByTrade = $tradeIds === [] ? [] : $this->exits->findByTradeIds($tradeIds);
        $valuesByTrade = $tradeIds === [] ? [] : $this->fieldValues->findByTradeIds($tradeIds);
        $fieldOrder = $valuesByTrade === [] ? [] : array_flip(array_map(
            fn ($d) => (int) $d['id'],
            $this->fieldDefinitions->findAllByUserId($userId)
        ));
        $setupCategories = [];
        foreach ($this->setups->findAllByUserId($userId) as $setup) {
            $setupCategories[$setup['label']] = $setup['category'] ?? null;
        }

        return [
            'filename' => $this->filename((string) $account['name'], $now),
            'sheets' => [
                $this->accountSheet($account, $adjustments, $labels, $now),
                $this->tradesSheet($trades, $exitsByTrade, $valuesByTrade, $fieldOrder, $setupCategories, $labels),
                $this->ordersSheet($orders, $setupCategories, $labels),
            ],
        ];
    }

    private function accountSheet(array $account, array $adjustments, array $labels, DateTimeImmutable $now): array
    {
        $fields = [
            'name' => $account['name'],
            'account_type' => self::translate($labels, 'account_type', $account['account_type']),
            'stage' => self::translate($labels, 'stage', $account['stage'] ?? null),
            'broker' => ($account['broker'] ?? '') !== '' ? $account['broker'] : null,
            'currency' => $account['currency'],
            'initial_capital' => self::number($account['initial_capital']),
            'current_capital' => self::number($account['current_capital']),
            'max_drawdown' => self::number($account['max_drawdown'] ?? null),
            'daily_drawdown' => self::number($account['daily_drawdown'] ?? null),
            'profit_target' => self::number($account['profit_target'] ?? null),
            'profit_split' => self::number($account['profit_split'] ?? null),
            'exported_at' => $now,
        ];

        $rows = [[$labels['field'], $labels['value']]];
        foreach ($fields as $key => $value) {
            $rows[] = [$labels['account'][$key], $value];
        }
        $headerRows = [1];

        if ($adjustments !== []) {
            usort($adjustments, fn ($a, $b) => [$a['adjusted_at'], $a['id'] ?? 0] <=> [$b['adjusted_at'], $b['id'] ?? 0]);
            $rows[] = [];
            $rows[] = array_values($labels['adjustments']);
            $headerRows[] = count($rows);
            foreach ($adjustments as $adjustment) {
                $rows[] = [
                    self::date($adjustment['adjusted_at']),
                    self::number($adjustment['amount']),
                    ($adjustment['reason'] ?? '') !== '' ? $adjustment['reason'] : null,
                ];
            }
        }

        return ['title' => $labels['sheets']['account'], 'header_rows' => $headerRows, 'rows' => $rows];
    }

    private function tradesSheet(array $trades, array $exitsByTrade, array $valuesByTrade, array $fieldOrder, array $setupCategories, array $labels): array
    {
        $rows = [self::header(self::TRADE_COLUMNS, $labels)];

        foreach ($trades as $trade) {
            $tradeId = (int) $trade['trade_id'];
            $common = $this->positionValues($trade, $setupCategories, $labels) + [
                'trade_number' => (int) $trade['position_id'],
                'status' => self::translate($labels, 'trade_status', $trade['trade_status']),
                'order_created_at' => self::date($trade['order_created_at'] ?? null),
                'opened_at' => self::date($trade['opened_at'] ?? null),
                'secured_at' => self::date($trade['secured_at'] ?? null),
                'closed_at' => self::date($trade['closed_at'] ?? null),
                'remaining_size' => self::number($trade['remaining_size'] ?? null),
                'avg_exit_price' => self::number($trade['avg_exit_price'] ?? null),
                'exit_type' => self::translate($labels, 'exit_type', $trade['exit_type'] ?? null),
                'risk_reward' => self::number($trade['risk_reward'] ?? null),
                'pnl' => self::number($trade['pnl'] ?? null),
                'custom_fields' => $this->customFields($valuesByTrade[$tradeId] ?? [], $fieldOrder, $labels),
            ];

            foreach ($this->tradeLegs(self::targets($trade), $exitsByTrade[$tradeId] ?? [], $labels) as $leg) {
                $rows[] = self::row(self::TRADE_COLUMNS, $leg + $common);
            }
        }

        return ['title' => $labels['sheets']['trades'], 'header_rows' => [1], 'rows' => $rows];
    }

    private function ordersSheet(array $orders, array $setupCategories, array $labels): array
    {
        $rows = [self::header(self::ORDER_COLUMNS, $labels)];

        foreach ($orders as $order) {
            $common = $this->positionValues($order, $setupCategories, $labels) + [
                'order_number' => (int) $order['position_id'],
                'status' => self::translate($labels, 'order_status', $order['order_status'] ?? null),
                'placed_at' => self::date($order['order_created_at'] ?? null),
                'expires_at' => self::date($order['order_expires_at'] ?? null),
            ];

            $targets = self::targets($order);
            $legs = $targets === [] ? [[]] : array_map(fn ($t) => self::targetValues($t), $targets);
            foreach ($legs as $leg) {
                $rows[] = self::row(self::ORDER_COLUMNS, $leg + $common);
            }
        }

        return ['title' => $labels['sheets']['orders'], 'header_rows' => [1], 'rows' => $rows];
    }

    /** What an order and a trade share: the position itself, its plan and context. */
    private function positionValues(array $position, array $setupCategories, array $labels): array
    {
        return self::setupsByCategory($position['setup'] ?? null, $setupCategories) + [
            'symbol' => $position['symbol'],
            'direction' => self::translate($labels, 'direction', $position['direction']),
            'size' => self::number($position['size']),
            'entry_price' => self::number($position['entry_price']),
            'sl_price' => self::number($position['sl_price'] ?? null),
            'be_price' => self::number($position['be_price'] ?? null),
            'be_size' => self::number($position['be_size'] ?? null),
            'plan' => $position['plan_name'] ?? null,
            'plan_adherence' => self::translate($labels, 'plan_adherence', $position['plan_adherence'] ?? null),
            'plan_adherence_reason' => $position['plan_adherence_reason'] ?? null,
            'notes' => ($position['notes'] ?? '') !== '' ? $position['notes'] : null,
        ];
    }

    /**
     * One leg per target, carrying each exit that hit it (a target hit by
     * several exits gives one leg per exit), then one leg per exit tied to no
     * known target. At least one leg, so a trade with nothing yet still shows.
     */
    private function tradeLegs(array $targets, array $exits, array $labels): array
    {
        $exitsByTarget = [];
        $loose = [];
        $knownIds = array_flip(array_map(fn ($t) => (string) ($t['id'] ?? ''), $targets));
        foreach ($exits as $exit) {
            $targetId = $exit['target_id'] ?? null;
            if ($targetId !== null && isset($knownIds[(string) $targetId])) {
                $exitsByTarget[(string) $targetId][] = $exit;
            } else {
                $loose[] = $exit;
            }
        }

        $legs = [];
        foreach ($targets as $target) {
            $hits = $exitsByTarget[(string) ($target['id'] ?? '')] ?? [null];
            foreach ($hits as $exit) {
                $legs[] = self::targetValues($target) + self::exitValues($exit, $labels);
            }
        }
        foreach ($loose as $exit) {
            $legs[] = self::exitValues($exit, $labels);
        }

        return $legs === [] ? [[]] : $legs;
    }

    private static function targetValues(array $target): array
    {
        return [
            'target' => $target['label'] ?? null,
            'target_price' => self::number($target['price'] ?? null),
            'target_size' => self::number($target['size'] ?? null),
        ];
    }

    private static function exitValues(?array $exit, array $labels): array
    {
        if ($exit === null) {
            return [];
        }
        return [
            'exit_at' => self::date($exit['exited_at']),
            'exit_price' => self::number($exit['exit_price']),
            'exit_size' => self::number($exit['size']),
            'exit_kind' => self::translate($labels, 'exit_type', $exit['exit_type']),
            'exit_pnl' => self::number($exit['pnl']),
        ];
    }

    /** Targets as stored, each with a label (TP1, TP2… when none was saved). */
    private static function targets(array $position): array
    {
        $targets = self::jsonList($position['targets'] ?? null) ?? [];
        foreach ($targets as $i => $target) {
            $targets[$i]['label'] = ($target['label'] ?? '') !== '' ? $target['label'] : 'TP' . ($i + 1);
        }
        return $targets;
    }

    /** "Name : value ; …" in the user's field order — any number of fields fits one cell. */
    private function customFields(array $values, array $fieldOrder, array $labels): ?string
    {
        if ($values === []) {
            return null;
        }
        usort($values, fn ($a, $b) => ($fieldOrder[(int) $a['field_id']] ?? PHP_INT_MAX) <=> ($fieldOrder[(int) $b['field_id']] ?? PHP_INT_MAX));

        $parts = [];
        foreach ($values as $value) {
            $raw = (string) ($value['value'] ?? '');
            if ($raw === '') {
                continue;
            }
            if ($value['field_type'] === CustomFieldType::BOOLEAN->value) {
                $raw = $raw === 'true' ? $labels['yes'] : $labels['no'];
            }
            $parts[] = $value['name'] . ' : ' . $raw;
        }
        return $parts === [] ? null : implode(' ; ', $parts);
    }

    private static function header(array $columns, array $labels): array
    {
        return array_map(fn ($c) => $labels['columns'][$c], $columns);
    }

    private static function row(array $columns, array $values): array
    {
        return array_map(fn ($c) => $values[$c] ?? null, $columns);
    }

    private static function translate(array $labels, string $group, ?string $code): ?string
    {
        return $code === null || $code === '' ? null : ($labels['values'][$group][$code] ?? $code);
    }

    /**
     * Setup tags spread over one column per category of the user's catalogue.
     * Setups are a JSON list of tags; older rows may hold free text, which —
     * like a tag with no category or gone from the catalogue — is uncategorized.
     */
    private static function setupsByCategory(?string $setup, array $setupCategories): array
    {
        $byColumn = [];
        if ($setup !== null && trim($setup) !== '') {
            $tags = self::jsonList($setup) ?? [$setup];
            foreach ($tags as $tag) {
                $tag = (string) $tag;
                if ($tag === '') {
                    continue;
                }
                $column = self::SETUP_COLUMNS[$setupCategories[$tag] ?? ''] ?? 'setup_uncategorized';
                $byColumn[$column][] = $tag;
            }
        }

        $values = [];
        foreach ([...array_values(self::SETUP_COLUMNS), 'setup_uncategorized'] as $column) {
            $values[$column] = isset($byColumn[$column]) ? implode(', ', $byColumn[$column]) : null;
        }
        return $values;
    }

    private static function jsonList(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) && array_is_list($decoded) ? $decoded : null;
    }

    private static function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private static function date(?string $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : new DateTimeImmutable($value);
    }

    private function filename(string $accountName, DateTimeImmutable $now): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($accountName)), '-');
        return ($slug !== '' ? $slug : 'account') . '-' . $now->format('Y-m-d') . '.xlsx';
    }
}
