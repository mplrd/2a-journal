<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Services\Import\ImportService;

class ImportController extends Controller
{
    private ImportService $importService;

    public function __construct(ImportService $importService)
    {
        $this->importService = $importService;
    }

    public function templates(Request $request): Response
    {
        return $this->jsonSuccess($this->importService->getAvailableTemplates());
    }

    /**
     * Upload a file and return its headers for custom column mapping.
     */
    public function headers(Request $request): Response
    {
        $file = $request->getFile('file');

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('import.error.no_file', 'file');
        }

        $result = $this->importService->getFileHeaders($file['tmp_name'], $file['name']);

        return $this->jsonSuccess($result);
    }

    public function preview(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $body = $request->getBody();
        $file = $request->getFile('file');

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('import.error.no_file', 'file');
        }

        $template = $this->resolveTemplate($body);
        $customFieldsMapping = json_decode($body['custom_fields_mapping'] ?? '{}', true) ?: [];

        $result = $this->importService->preview($file['tmp_name'], $template, $file['name'], $customFieldsMapping);
        $result['original_filename'] = $file['name'];

        return $this->jsonSuccess($result);
    }

    public function confirm(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $body = $request->getBody();
        $file = $request->getFile('file');

        $accountId = (int) ($body['account_id'] ?? 0);
        $symbolMapping = json_decode($body['symbol_mapping'] ?? '{}', true) ?: [];

        if (!$accountId) {
            throw new ValidationException('import.error.account_required', 'account_id');
        }

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('import.error.no_file', 'file');
        }

        $template = $this->resolveTemplate($body);
        $customFieldsMapping = json_decode($body['custom_fields_mapping'] ?? '{}', true) ?: [];

        $result = $this->importService->confirm(
            $userId,
            $accountId,
            $file['tmp_name'],
            $template,
            $symbolMapping,
            $file['name'],
            $customFieldsMapping
        );

        return $this->jsonSuccess($result);
    }

    /**
     * Resolve template from request: either a known broker or a custom column_mapping.
     */
    private function resolveTemplate(array $body): array
    {
        $broker = $body['broker'] ?? null;

        if ($broker && $broker !== 'custom') {
            $template = $this->importService->loadTemplate($broker);
            if (!$template) {
                throw new ValidationException('import.error.unknown_broker', 'broker');
            }
            return $template;
        }

        // Custom mapping mode
        $columnMapping = json_decode($body['column_mapping'] ?? '{}', true) ?: [];
        if (empty($columnMapping)) {
            throw new ValidationException('import.error.mapping_required', 'column_mapping');
        }

        return $this->importService->buildCustomTemplate($columnMapping, $body);
    }

    public function downloadTemplate(Request $request): never
    {
        $format = strtolower((string) ($request->getQuery('format') ?? 'csv'));
        if (!in_array($format, ['csv', 'xlsx', 'ods'], true)) {
            $format = 'csv';
        }

        if ($format === 'csv') {
            $filePath = __DIR__ . '/../../public/templates/import-template.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="import-template.csv"');
            readfile($filePath);
            exit;
        }

        // xlsx / ods — built on the fly from the same column structure as the
        // CSV template so all formats stay in sync from a single source of truth.
        $headers = ['Symbol', 'Direction', 'Entry Price', 'Size', 'Open Date', 'Close Date', 'Exit Price'];
        $sampleRows = [
            ['EURUSD', 'BUY', 1.1000, 1.0, '2024-01-15 09:30:00', '2024-01-15 10:00:00', 1.1050],
            ['NASDAQ', 'SELL', 18500, 2, '2024-01-16 14:00:00', '', ''],
        ];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($sampleRows, null, 'A2');

        if ($format === 'xlsx') {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="import-template.xlsx"');
        } else {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Ods($spreadsheet);
            header('Content-Type: application/vnd.oasis.opendocument.spreadsheet');
            header('Content-Disposition: attachment; filename="import-template.ods"');
        }

        $writer->save('php://output');
        exit;
    }

    public function batches(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        return $this->jsonSuccess($this->importService->listBatches($userId));
    }

    public function rollback(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $batchId = (int) $request->getAttribute('id');

        $this->importService->rollback($batchId, $userId);

        return $this->jsonSuccess(['message_key' => 'import.success.rolled_back']);
    }
}
