<?php

namespace App\Services\Import;

use App\Exceptions\ValidationException;

class ColumnMapperService
{
    /**
     * Map file headers to normalized field names using a broker template.
     * Returns array: normalized_field => actual_header_name.
     *
     * @return array<string, string>
     */
    public function mapColumns(array $headers, array $template): array
    {
        $mapping = [];
        $missing = [];

        foreach ($template['columns'] as $field => $colDef) {
            $found = $this->findColumn($headers, $colDef);
            if ($found !== null) {
                $mapping[$field] = $found;
            } else {
                // Only symbol, direction, closed_at, entry_price are strictly required
                if (!in_array($field, ['pips', 'comment', 'opened_at', 'closed_at', 'exit_price', 'size', 'pnl'])) {
                    $missing[] = $field;
                }
            }
        }

        if (!empty($missing)) {
            throw new ValidationException(
                'import.error.missing_columns',
                'columns',
            );
        }

        return $mapping;
    }

    /**
     * Detect the currency from PnL column header.
     */
    public function detectCurrency(array $headers, array $template): ?string
    {
        $pnlDef = $template['columns']['pnl'] ?? null;
        if ($pnlDef === null) {
            return null;
        }

        $pnlHeader = $this->findColumn($headers, $pnlDef);
        if ($pnlHeader === null) {
            return null;
        }

        // Extract currency from header like "EUR nets", "$ nets", "€ nets", "USD nets"
        $currencyMap = ['€' => 'EUR', '$' => 'USD', '£' => 'GBP'];

        foreach ($currencyMap as $symbol => $code) {
            if (str_contains($pnlHeader, $symbol)) {
                return $code;
            }
        }

        // Try 3-letter currency codes
        if (preg_match('/\b([A-Z]{3})\b/', $pnlHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Apply value mapping (e.g. "Buy" → "BUY") for a column.
     */
    public function applyValueMappings(array $row, string $headerName, array $colDef): mixed
    {
        $value = $row[$headerName] ?? null;
        if ($value === null) {
            return null;
        }

        if (isset($colDef['map'])) {
            return $colDef['map'][$value] ?? $value;
        }

        return $value;
    }

    /**
     * Parse a date string according to the template format.
     */
    public function parseDate(string $dateStr, string $format): string
    {
        // Truncate milliseconds if present
        $dateStr = preg_replace('/\.\d+$/', '', $dateStr);

        $dt = \DateTime::createFromFormat($format, $dateStr);
        if ($dt === false) {
            // Try common fallback formats
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $dateStr);
        }
        if ($dt === false) {
            throw new ValidationException('import.error.invalid_date', 'closed_at');
        }

        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * Convert a raw row to normalized field names with value mappings applied.
     */
    public function mapRow(array $rawRow, array $columnMapping, array $template): array
    {
        $normalized = [];

        foreach ($columnMapping as $field => $headerName) {
            $colDef = $template['columns'][$field] ?? [];
            $value = $rawRow[$headerName] ?? null;

            // Treat empty strings as null
            if ($value === '' || $value === null) {
                $normalized[$field] = null;
                continue;
            }

            // Apply value mapping (e.g. direction)
            if (isset($colDef['map'])) {
                $value = $colDef['map'][$value] ?? $value;
            }

            // Parse dates
            if (in_array($field, ['closed_at', 'opened_at']) && isset($colDef['format'])) {
                $value = $this->parseDate((string) $value, $colDef['format']);
            }

            // Cast numeric fields (strip thousands separator if configured)
            if (in_array($field, ['entry_price', 'exit_price', 'size', 'pnl', 'pips'])) {
                $thousandsSep = $template['thousands_separator'] ?? null;
                if ($thousandsSep !== null) {
                    $value = str_replace($thousandsSep, '', (string) $value);
                }
                $value = (float) $value;
            }

            $normalized[$field] = $value;
        }

        return $normalized;
    }

    /**
     * Merge multi-row records into single rows.
     * Used for formats like FXCM where each trade spans 2 rows (open + close).
     *
     * @return array[] Merged rows with synthetic _direction, _entry_price, _exit_price, _opened_at, _closed_at fields
     */
    public function mergeMultiRows(array $rows, array $template): array
    {
        $multiRow = $template['multi_row'] ?? 1;
        if ($multiRow <= 1) {
            return $rows;
        }

        $mergeConfig = $template['multi_row_merge'] ?? [];

        // Filter rows before merging if template defines a row_filter
        if (isset($template['row_filter'])) {
            $filterCol = $template['row_filter']['column'];
            $filterPattern = $template['row_filter']['pattern'];
            $allowEmpty = $template['row_filter']['allow_empty'] ?? false;
            $filtered = [];
            foreach ($rows as $row) {
                $value = trim((string) ($row[$filterCol] ?? ''));
                if (($allowEmpty && $value === '') || preg_match($filterPattern, $value)) {
                    $filtered[] = $row;
                }
            }
            $rows = $filtered;
        }

        $merged = [];

        for ($i = 0; $i + $multiRow - 1 < count($rows); $i += $multiRow) {
            $openRow = $rows[$i];
            $closeRow = $rows[$i + 1];

            // Skip pairs where the open row has no symbol (summary/footer rows)
            $symbolCol = $mergeConfig['direction_from']['sell_column'] ?? null;
            $buyCol2 = $mergeConfig['direction_from']['buy_column'] ?? null;
            $hasSellPrice = isset($openRow[$symbolCol]) && $openRow[$symbolCol] !== null;
            $hasBuyPrice = isset($openRow[$buyCol2]) && $openRow[$buyCol2] !== null;
            if (!$hasSellPrice && !$hasBuyPrice) {
                continue;
            }

            // Determine direction from which price column is filled on the open row
            $sellCol = $mergeConfig['direction_from']['sell_column'] ?? null;
            $buyCol = $mergeConfig['direction_from']['buy_column'] ?? null;

            $sellPrice = $openRow[$sellCol] ?? null;
            $buyPrice = $openRow[$buyCol] ?? null;

            if ($sellPrice !== null) {
                $direction = 'SELL';
                $entryPrice = $sellPrice;
                $exitPrice = $closeRow[$buyCol] ?? null;
            } else {
                $direction = 'BUY';
                $entryPrice = $buyPrice;
                $exitPrice = $closeRow[$sellCol] ?? null;
            }

            // Build merged row: keep open row data + add synthetic fields
            $mergedRow = $openRow;
            $mergedRow['_direction'] = $direction;
            $mergedRow['_entry_price'] = $entryPrice;
            $mergedRow['_exit_price'] = $exitPrice;

            // Opened/closed dates
            $openDateCol = $mergeConfig['opened_at']['column'] ?? 'Date';
            $closeDateCol = $mergeConfig['closed_at']['column'] ?? 'Date';
            $mergedRow['_opened_at'] = $openRow[$openDateCol] ?? null;
            $mergedRow['_closed_at'] = $closeRow[$closeDateCol] ?? null;

            // Carry over close-row-only fields (PnL, pips, etc.)
            $pnlCol = $mergeConfig['pnl']['column'] ?? null;
            if ($pnlCol && isset($closeRow[$pnlCol])) {
                $mergedRow[$pnlCol] = $closeRow[$pnlCol];
            }
            $pipsCol = $mergeConfig['pips']['column'] ?? null;
            if ($pipsCol && isset($closeRow[$pipsCol])) {
                $mergedRow[$pipsCol] = $closeRow[$pipsCol];
            }

            $merged[] = $mergedRow;
        }

        return $merged;
    }

    /**
     * Find a matching header for a column definition.
     */
    private function findColumn(array $headers, array $colDef): ?string
    {
        // Match by exact name
        if (isset($colDef['names'])) {
            foreach ($colDef['names'] as $name) {
                foreach ($headers as $header) {
                    if (strcasecmp(trim($header), $name) === 0) {
                        return $header;
                    }
                }
            }
        }

        // Match by regex pattern
        if (isset($colDef['names_pattern'])) {
            foreach ($colDef['names_pattern'] as $pattern) {
                foreach ($headers as $header) {
                    if (preg_match($pattern, $header)) {
                        return $header;
                    }
                }
            }
        }

        return null;
    }
}
