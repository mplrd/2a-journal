<?php

namespace App\Services;

use App\Enums\Direction;
use App\Enums\PositionType;
use App\Enums\TradeStatus;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Repositories\PositionRepository;
use App\Repositories\TradeRepository;

class ShareService
{
    private PositionRepository $positionRepo;
    private TradeRepository $tradeRepo;

    public function __construct(PositionRepository $positionRepo, TradeRepository $tradeRepo)
    {
        $this->positionRepo = $positionRepo;
        $this->tradeRepo = $tradeRepo;
    }

    public function generateText(int $userId, int $positionId): string
    {
        $position = $this->getPosition($userId, $positionId);
        $trade = $this->getTradeIfApplicable($position);

        return $this->format($position, $trade, true);
    }

    public function generateTextPlain(int $userId, int $positionId): string
    {
        $position = $this->getPosition($userId, $positionId);
        $trade = $this->getTradeIfApplicable($position);

        return $this->format($position, $trade, false);
    }

    private function getPosition(int $userId, int $positionId): array
    {
        $position = $this->positionRepo->findById($positionId);

        if (!$position) {
            throw new NotFoundException('positions.error.not_found');
        }

        if ((int) $position['user_id'] !== $userId) {
            throw new ForbiddenException('positions.error.forbidden');
        }

        return $position;
    }

    private function getTradeIfApplicable(array $position): ?array
    {
        if ($position['position_type'] === PositionType::TRADE->value) {
            return $this->tradeRepo->findByPositionId((int) $position['id']);
        }

        return null;
    }

    private function format(array $position, ?array $trade, bool $withEmojis): string
    {
        $isClosedTrade = $trade && $trade['status'] === TradeStatus::CLOSED->value;

        if ($isClosedTrade) {
            return $this->formatClosedTrade($position, $trade, $withEmojis);
        }

        return $this->formatOpenPosition($position, $withEmojis);
    }

    private function formatOpenPosition(array $position, bool $withEmojis): string
    {
        $lines = [];

        // Header: direction + symbol + entry price
        $lines[] = $this->formatHeader($position, $withEmojis);

        // Targets
        $targets = $this->parseTargets($position['targets']);
        if ($targets) {
            foreach ($this->formatTargetLines($targets, $withEmojis) as $line) {
                $lines[] = $line;
            }
        }

        // BE
        if ($position['be_points'] !== null) {
            $bePrice = $this->num((float) $position['be_price']);
            $bePoints = $this->num((float) $position['be_points']);
            $lines[] = $withEmojis
                ? "🔒 BE: {$bePrice} (+{$bePoints} pts)"
                : "BE: {$bePrice} (+{$bePoints} pts)";
        }

        // SL
        $slPrice = $this->num((float) $position['sl_price']);
        $slPoints = $this->num((float) $position['sl_points']);
        $lines[] = $withEmojis
            ? "🛑 SL: {$slPrice} (-{$slPoints} pts)"
            : "SL: {$slPrice} (-{$slPoints} pts)";

        // R/R (from first target)
        if ($targets) {
            $rr = $this->num((float) $targets[0]['points'] / (float) $position['sl_points']);
            $lines[] = $withEmojis
                ? "⚖️ R/R: {$rr}"
                : "R/R: {$rr}";
        }

        // Setup
        $lines[] = '';
        $lines[] = $withEmojis
            ? "💬 {$position['setup']}"
            : $position['setup'];

        return implode("\n", $lines);
    }

    private function formatClosedTrade(array $position, array $trade, bool $withEmojis): string
    {
        $lines = [];

        // Header with exit price
        $dirEmoji = $position['direction'] === Direction::BUY->value ? '📈' : '📉';
        $entryPrice = $this->num((float) $position['entry_price']);
        $exitPrice = $this->num((float) $trade['avg_exit_price']);
        $lines[] = $withEmojis
            ? "{$dirEmoji} {$position['direction']} {$position['symbol']} @ {$entryPrice} → {$exitPrice}"
            : "{$position['direction']} {$position['symbol']} @ {$entryPrice} → {$exitPrice}";

        // PnL
        $pnl = (float) $trade['pnl'];
        $pnlFormatted = $this->formatPnl($pnl);
        $pnlPercent = $this->formatPercent((float) $trade['pnl_percent']);
        $pnlEmoji = $pnl >= 0 ? '✅' : '❌';
        $lines[] = $withEmojis
            ? "{$pnlEmoji} PnL: {$pnlFormatted} ({$pnlPercent})"
            : "PnL: {$pnlFormatted} ({$pnlPercent})";

        // Exit type
        if ($trade['exit_type']) {
            $lines[] = $withEmojis
                ? "🎯 Exit: {$trade['exit_type']}"
                : "Exit: {$trade['exit_type']}";
        }

        // R/R
        $rr = $this->num((float) $trade['risk_reward']);
        $lines[] = $withEmojis
            ? "⚖️ R/R: {$rr}"
            : "R/R: {$rr}";

        // Duration
        if ($trade['duration_minutes'] !== null) {
            $duration = $this->formatDuration((int) $trade['duration_minutes']);
            $lines[] = $withEmojis
                ? "⏱️ {$duration}"
                : $duration;
        }

        // Setup
        $lines[] = '';
        $lines[] = $withEmojis
            ? "💬 {$position['setup']}"
            : $position['setup'];

        return implode("\n", $lines);
    }

    private function formatHeader(array $position, bool $withEmojis): string
    {
        $dirEmoji = $position['direction'] === Direction::BUY->value ? '📈' : '📉';
        $entryPrice = $this->num((float) $position['entry_price']);

        return $withEmojis
            ? "{$dirEmoji} {$position['direction']} {$position['symbol']} @ {$entryPrice}"
            : "{$position['direction']} {$position['symbol']} @ {$entryPrice}";
    }

    private function formatTargetLines(array $targets, bool $withEmojis): array
    {
        $lines = [];
        $multiple = count($targets) > 1;

        foreach ($targets as $i => $target) {
            $label = $multiple ? 'TP' . ($i + 1) : 'TP';
            $price = $this->num((float) $target['price']);
            $points = $this->num((float) $target['points']);
            $lines[] = $withEmojis
                ? "🎯 {$label}: {$price} (+{$points} pts)"
                : "{$label}: {$price} (+{$points} pts)";
        }

        return $lines;
    }

    private function parseTargets(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }

        $targets = json_decode($json, true);

        return is_array($targets) && count($targets) > 0 ? $targets : null;
    }

    private function formatPnl(float $pnl): string
    {
        $sign = $pnl >= 0 ? '+' : '';
        return $sign . $this->num($pnl);
    }

    private function formatPercent(float $percent): string
    {
        $sign = $percent >= 0 ? '+' : '';
        return $sign . number_format($percent, 2, '.', '') . '%';
    }

    private function formatDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes}min";
        }

        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        if ($remainder === 0) {
            return "{$hours}h";
        }

        return "{$hours}h{$remainder}";
    }

    /**
     * Format a number, stripping unnecessary trailing zeros.
     */
    private function num(float $value): string
    {
        // Use enough precision, then strip trailing zeros
        $formatted = number_format($value, 10, '.', '');
        $formatted = rtrim($formatted, '0');
        $formatted = rtrim($formatted, '.');

        return $formatted;
    }
}
