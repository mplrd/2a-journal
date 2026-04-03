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
                // pips, comment and opened_at are optional
                if (!in_array($field, ['pips', 'comment', 'opened_at'])) {
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

            // Apply value mapping (e.g. direction)
            if (isset($colDef['map']) && $value !== null) {
                $value = $colDef['map'][$value] ?? $value;
            }

            // Parse dates
            if (in_array($field, ['closed_at', 'opened_at']) && $value !== null && isset($colDef['format'])) {
                $value = $this->parseDate((string) $value, $colDef['format']);
            }

            // Cast numeric fields
            if (in_array($field, ['entry_price', 'exit_price', 'size', 'pnl', 'pips']) && $value !== null) {
                $value = (float) $value;
            }

            $normalized[$field] = $value;
        }

        return $normalized;
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
