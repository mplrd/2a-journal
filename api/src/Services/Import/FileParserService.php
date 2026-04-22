<?php

namespace App\Services\Import;

use App\Exceptions\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class FileParserService
{
    private const ALLOWED_EXTENSIONS = ['xlsx', 'xls', 'xlsm', 'csv', 'xml'];
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB

    /**
     * Parse a file and return rows as associative arrays keyed by header names.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $filePath, ?string $originalName = null): array
    {
        [$rows] = $this->parseWithHeaders($filePath, $originalName);
        return $rows;
    }

    /**
     * Parse a file and return [rows, headers].
     *
     * @return array{0: array<int, array<string, mixed>>, 1: string[]}
     */
    public function parseWithHeaders(string $filePath, ?string $originalName = null): array
    {
        $extension = $this->validateFile($filePath, $originalName);

        if ($extension === 'csv') {
            return $this->parseCsv($filePath);
        }

        if ($extension === 'xml') {
            return $this->parseSpreadsheetMl($filePath);
        }

        return $this->parseSpreadsheet($filePath);
    }

    /**
     * Validate and return the file extension.
     */
    private function validateFile(string $filePath, ?string $originalName = null): string
    {
        if (!file_exists($filePath)) {
            throw new ValidationException('import.error.file_not_found', 'file');
        }

        // Use original filename for extension check (temp files have no extension)
        $nameForExtension = $originalName ?? $filePath;
        $extension = strtolower(pathinfo($nameForExtension, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            throw new ValidationException('import.error.unsupported_file_type', 'file');
        }

        if (filesize($filePath) > self::MAX_FILE_SIZE) {
            throw new ValidationException('import.error.file_too_large', 'file');
        }

        return $extension;
    }

    /**
     * Deduplicate headers by appending _2, _3, etc. to repeated names.
     */
    private function deduplicateHeaders(array $headers): array
    {
        $counts = [];
        $result = [];

        foreach ($headers as $header) {
            if (!isset($counts[$header])) {
                $counts[$header] = 1;
                $result[] = $header;
            } else {
                $counts[$header]++;
                $result[] = $header . '_' . $counts[$header];
            }
        }

        return $result;
    }

    private function parseSpreadsheet(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, false);

        if (count($data) < 2) {
            throw new ValidationException('import.error.empty_file', 'file');
        }

        $headers = $this->deduplicateHeaders(array_map('trim', $data[0]));
        $rows = [];

        for ($i = 1; $i < count($data); $i++) {
            $row = [];
            foreach ($headers as $colIdx => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = $data[$i][$colIdx] ?? null;
            }
            // Skip fully empty rows
            if (array_filter($row, fn($v) => $v !== null && $v !== '') === []) {
                continue;
            }
            $rows[] = $row;
        }

        return [$rows, $headers];
    }

    private function parseCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new ValidationException('import.error.file_not_found', 'file');
        }

        // Auto-detect delimiter (semicolon vs comma)
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $headerRow = fgetcsv($handle, 0, $delimiter);
        if ($headerRow === false || count($headerRow) < 2) {
            fclose($handle);
            throw new ValidationException('import.error.empty_file', 'file');
        }

        $headers = $this->deduplicateHeaders(array_map('trim', $headerRow));
        $rows = [];

        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            $row = [];
            foreach ($headers as $colIdx => $header) {
                if ($header === '') {
                    continue;
                }
                $value = $line[$colIdx] ?? null;
                // Try to cast numeric strings
                if (is_string($value) && is_numeric($value)) {
                    $value = strpos($value, '.') !== false ? (float) $value : (int) $value;
                }
                $row[$header] = $value;
            }
            if (array_filter($row, fn($v) => $v !== null && $v !== '') === []) {
                continue;
            }
            $rows[] = $row;
        }

        fclose($handle);
        return [$rows, $headers];
    }

    /**
     * Parse a SpreadsheetML (XML) file, preserving raw cell values as strings.
     * Auto-detects the header row by finding a row with ≥5 non-empty cells.
     */
    private function parseSpreadsheetMl(string $filePath): array
    {
        $xml = simplexml_load_file($filePath);
        if ($xml === false) {
            throw new ValidationException('import.error.file_not_found', 'file');
        }

        $ns = ['ss' => 'urn:schemas-microsoft-com:office:spreadsheet'];

        $worksheet = $xml->children($ns['ss'])->Worksheet;
        if (!$worksheet) {
            throw new ValidationException('import.error.empty_file', 'file');
        }

        $table = $worksheet->children($ns['ss'])->Table;
        if (!$table) {
            throw new ValidationException('import.error.empty_file', 'file');
        }

        $allRows = $table->children($ns['ss'])->Row;
        if (!$allRows || count($allRows) < 2) {
            throw new ValidationException('import.error.empty_file', 'file');
        }

        // Extract all rows as arrays of cell values
        $parsedRows = [];
        foreach ($allRows as $xmlRow) {
            $cells = [];
            $colIndex = 0;

            foreach ($xmlRow->children($ns['ss'])->Cell as $cell) {
                // Handle ss:Index attribute (1-based column positioning)
                $attrs = $cell->attributes($ns['ss']);
                if (isset($attrs['Index'])) {
                    $colIndex = (int) $attrs['Index'] - 1;
                }

                $data = $cell->children($ns['ss'])->Data;
                $value = $data ? trim((string) $data) : '';

                $cells[$colIndex] = $value;
                $colIndex++;
            }

            $parsedRows[] = $cells;
        }

        // Find header row: the row with the most non-empty cells among the first 50 rows.
        // This handles SpreadsheetML files with metadata rows before the actual headers.
        $headerRowIdx = null;
        $maxNonEmpty = 0;
        $searchLimit = min(50, count($parsedRows));
        for ($idx = 0; $idx < $searchLimit; $idx++) {
            $nonEmpty = count(array_filter($parsedRows[$idx], fn($v) => $v !== ''));
            if ($nonEmpty > $maxNonEmpty) {
                $maxNonEmpty = $nonEmpty;
                $headerRowIdx = $idx;
            }
        }

        if ($headerRowIdx === null) {
            throw new ValidationException('import.error.empty_file', 'file');
        }

        $rawHeaders = $parsedRows[$headerRowIdx];
        // Build headers indexed by column position
        $maxCol = max(array_keys($rawHeaders));
        $headers = [];
        for ($c = 0; $c <= $maxCol; $c++) {
            $headers[$c] = $rawHeaders[$c] ?? '';
        }
        $headers = $this->deduplicateHeaders($headers);

        // Parse data rows after header
        $rows = [];
        for ($i = $headerRowIdx + 1; $i < count($parsedRows); $i++) {
            $row = [];
            $hasData = false;
            foreach ($headers as $colIdx => $header) {
                if ($header === '') {
                    continue;
                }
                $value = $parsedRows[$i][$colIdx] ?? null;
                // Treat whitespace-only as empty
                if (is_string($value) && trim($value) === '') {
                    $value = null;
                }
                $row[$header] = $value;
                if ($value !== null) {
                    $hasData = true;
                }
            }

            // Skip fully empty rows and summary rows (containing "Total:")
            if (!$hasData) {
                continue;
            }
            $isSummary = false;
            foreach ($row as $v) {
                if (is_string($v) && str_contains($v, 'Total:')) {
                    $isSummary = true;
                    break;
                }
            }
            if ($isSummary) {
                continue;
            }

            $rows[] = $row;
        }

        if (empty($rows)) {
            throw new ValidationException('import.error.empty_file', 'file');
        }

        return [$rows, $headers];
    }
}
