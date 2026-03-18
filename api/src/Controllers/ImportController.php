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

        $headers = $this->importService->getFileHeaders($file['tmp_name'], $file['name']);

        return $this->jsonSuccess(['headers' => $headers]);
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

        $result = $this->importService->preview($file['tmp_name'], $template, $file['name']);
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

        $result = $this->importService->confirm(
            $userId,
            $accountId,
            $file['tmp_name'],
            $template,
            $symbolMapping,
            $file['name']
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
