<?php

namespace App\Services\Import;

use App\Exceptions\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class FileParserService
{
    private const ALLOWED_EXTENSIONS = ['xlsx', 'csv'];
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
}
