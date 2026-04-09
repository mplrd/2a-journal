<?php

namespace App\Services\Import;

use App\Enums\ExitType;
use App\Enums\ImportStatus;
use App\Enums\SymbolType;
use App\Enums\TradeStatus;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\ImportBatchRepository;
use App\Repositories\PositionRepository;
use App\Repositories\SymbolAliasRepository;
use App\Repositories\SymbolRepository;
use App\Repositories\TradeRepository;
use App\Services\CustomFieldService;
use PDO;

class ImportService
{
    private FileParserService $parser;
    private ColumnMapperService $mapper;
    private RowGroupingService $grouper;
    private ImportBatchRepository $batchRepo;
    private SymbolAliasRepository $aliasRepo;
    private SymbolRepository $symbolRepo;
    private PositionRepository $positionRepo;
    private TradeRepository $tradeRepo;
    private AccountRepository $accountRepo;
    private PDO $pdo;
    private ?CustomFieldService $customFieldService;

    public function __construct(
        FileParserService $parser,
        ColumnMapperService $mapper,
        RowGroupingService $grouper,
        ImportBatchRepository $batchRepo,
        SymbolAliasRepository $aliasRepo,
        SymbolRepository $symbolRepo,
        PositionRepository $positionRepo,
        TradeRepository $tradeRepo,
        AccountRepository $accountRepo,
        PDO $pdo,
        ?CustomFieldService $customFieldService = null
    ) {
        $this->parser = $parser;
        $this->mapper = $mapper;
        $this->grouper = $grouper;
        $this->batchRepo = $batchRepo;
        $this->aliasRepo = $aliasRepo;
        $this->symbolRepo = $symbolRepo;
        $this->positionRepo = $positionRepo;
        $this->tradeRepo = $tradeRepo;
        $this->accountRepo = $accountRepo;
        $this->pdo = $pdo;
        $this->customFieldService = $customFieldService;
    }

    /**
     * Parse a file and return a preview without persisting anything.
     *
     * @param array $customFieldsMapping Optional mapping: field_id => file column header name
     */
    public function preview(string $filePath, array $template, ?string $originalFilename = null, array $customFieldsMapping = []): array
    {
        [$rows, $headers] = $this->parser->parseWithHeaders($filePath, $originalFilename);

        // Merge multi-row records if template requires it (e.g. FXCM: 2 rows per trade)
        if (($template['multi_row'] ?? 1) > 1) {
            $rows = $this->mapper->mergeMultiRows($rows, $template);
            // Update headers from merged row keys
            if (!empty($rows)) {
                $headers = array_keys($rows[0]);
            }
        }

        $columnMapping = $this->mapper->mapColumns($headers, $template);
        $currency = $this->mapper->detectCurrency($headers, $template);

        // Normalize all rows and fill defaults for missing fields
        $normalized = [];
        foreach ($rows as $row) {
            $norm = $this->mapper->mapRow($row, $columnMapping, $template);
            $norm = $this->fillRowDefaults($norm);
            $normalized[] = $norm;
        }

        // Group into positions
        $groupKey = $template['grouping']['key'] ?? ['symbol', 'direction', 'entry_price'];
        $positions = $this->grouper->group($normalized, $groupKey);

        // Attach custom field values from raw rows
        $this->attachCustomFieldValues($positions, $rows, $normalized, $groupKey, $customFieldsMapping);

        // Collect unknown symbols (all broker symbols for now — resolve during confirm)
        $brokerSymbols = array_unique(array_column($positions, 'symbol'));
        sort($brokerSymbols);

        return [
            'total_rows' => count($rows),
            'total_positions' => count($positions),
            'positions' => $positions,
            'unknown_symbols' => array_values($brokerSymbols),
            'currency' => $currency,
        ];
    }

    /**
     * Confirm an import: persist positions, trades, and partial exits.
     */
    /**
     * @param array $customFieldsMapping Optional mapping: field_id => file column header name
     */
    public function confirm(
        int $userId,
        int $accountId,
        string $filePath,
        array $template,
        array $symbolMapping = [],
        string $originalFilename = '',
        array $customFieldsMapping = []
    ): array {
        // Validate account ownership
        $account = $this->accountRepo->findById($accountId);
        if (!$account || (int) $account['user_id'] !== $userId) {
            throw new ForbiddenException('import.error.account_required');
        }

        // Parse and preview (with custom fields extraction)
        $preview = $this->preview($filePath, $template, $originalFilename, $customFieldsMapping);
        $positions = $preview['positions'];

        // Check for duplicates
        $existingExternalIds = $this->getExistingExternalIds($userId);

        $this->pdo->beginTransaction();

        try {
            // Create import batch
            $batchId = $this->batchRepo->create([
                'user_id' => $userId,
                'account_id' => $accountId,
                'broker_template' => $template['broker'] ?? null,
                'original_filename' => $originalFilename ?: basename($filePath),
                'file_hash' => hash_file('sha256', $filePath),
                'total_rows' => $preview['total_rows'],
                'status' => ImportStatus::PROCESSING->value,
            ]);

            $importedPositions = 0;
            $importedTrades = 0;
            $skippedDuplicates = 0;
            $errors = [];

            foreach ($positions as $posData) {
                // Check duplicate
                if (in_array($posData['external_id'], $existingExternalIds)) {
                    $skippedDuplicates++;
                    continue;
                }

                try {
                    // Resolve symbol (auto-creates in user assets if missing)
                    $symbol = $this->resolveSymbol($userId, $posData['symbol'], $symbolMapping, $template['broker'] ?? null, $account['currency'] ?? 'EUR');

                    // Create position
                    $position = $this->positionRepo->create([
                        'user_id' => $userId,
                        'account_id' => $accountId,
                        'direction' => $posData['direction'],
                        'symbol' => $symbol,
                        'entry_price' => $posData['entry_price'],
                        'size' => $posData['total_size'],
                        'setup' => null,
                        'sl_points' => null,
                        'sl_price' => null,
                        'notes' => $posData['comment'],
                        'import_batch_id' => $batchId,
                        'external_id' => $posData['external_id'],
                        'position_type' => 'TRADE', // positions table ENUM, no PHP enum exists
                    ]);

                    // Create trade — OPEN if no closed_at, CLOSED otherwise
                    $isOpen = $this->isOpenPosition($posData);
                    $tradeId = $isOpen
                        ? $this->createOpenImportedTrade($position['id'], $posData)
                        : $this->createImportedTrade($position['id'], $posData);

                    // Create partial exits only for closed trades
                    if (!$isOpen) {
                        foreach ($posData['exits'] as $exit) {
                            $this->createPartialExit($tradeId, $exit);
                        }
                    }

                    // Save custom field values if mapping provided
                    if ($this->customFieldService && !empty($posData['custom_fields'])) {
                        try {
                            $this->customFieldService->validateAndSaveValues($userId, $tradeId, $posData['custom_fields']);
                        } catch (\Throwable $e) {
                            $errors[] = [
                                'symbol' => $posData['symbol'],
                                'error' => 'custom_fields: ' . $e->getMessage(),
                            ];
                        }
                    }

                    $importedPositions++;
                    $importedTrades++;
                } catch (\Throwable $e) {
                    $errors[] = [
                        'symbol' => $posData['symbol'],
                        'error' => $e->getMessage(),
                    ];
                }
            }

            // Update batch
            $this->batchRepo->update($batchId, [
                'status' => ImportStatus::COMPLETED->value,
                'imported_positions' => $importedPositions,
                'imported_trades' => $importedTrades,
                'skipped_duplicates' => $skippedDuplicates,
                'skipped_errors' => count($errors),
                'error_log' => !empty($errors) ? json_encode($errors) : null,
                'completed_at' => date('Y-m-d H:i:s'),
            ]);

            $this->pdo->commit();

            return [
                'batch_id' => $batchId,
                'imported_positions' => $importedPositions,
                'imported_trades' => $importedTrades,
                'skipped_duplicates' => $skippedDuplicates,
                'skipped_errors' => count($errors),
                'errors' => $errors,
            ];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Rollback an import: delete all positions from a batch.
     */
    public function rollback(int $batchId, int $userId): void
    {
        $batch = $this->batchRepo->findById($batchId);
        if (!$batch || (int) $batch['user_id'] !== $userId) {
            throw new ValidationException('import.error.batch_not_found', 'id');
        }

        $this->pdo->beginTransaction();
        try {
            // Delete positions (CASCADE will clean trades + partial_exits)
            $stmt = $this->pdo->prepare("DELETE FROM positions WHERE import_batch_id = :batch_id AND user_id = :user_id");
            $stmt->execute(['batch_id' => $batchId, 'user_id' => $userId]);

            $this->batchRepo->update($batchId, [
                'status' => ImportStatus::ROLLED_BACK->value,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * List import batches for a user.
     */
    public function listBatches(int $userId): array
    {
        return $this->batchRepo->findAllByUserId($userId);
    }

    /**
     * Get available broker templates.
     */
    public function getAvailableTemplates(): array
    {
        $templatesDir = __DIR__ . '/../../../config/import_templates';
        $templates = [];

        foreach (glob($templatesDir . '/*.php') as $file) {
            $template = require $file;
            $templates[] = [
                'broker' => $template['broker'],
                'label' => $template['label'],
                'file_types' => $template['file_types'] ?? ['xlsx', 'csv'],
            ];
        }

        return $templates;
    }

    /**
     * Get file headers and first data row for mapping preview.
     */
    public function getFileHeaders(string $filePath, ?string $originalFilename = null): array
    {
        [$rows, $headers] = $this->parser->parseWithHeaders($filePath, $originalFilename);

        // Build sample: header → first row value
        $sample = [];
        if (!empty($rows)) {
            foreach ($headers as $header) {
                $sample[$header] = $rows[0][$header] ?? null;
            }
        }

        return ['headers' => $headers, 'sample' => $sample];
    }

    /**
     * Build a template from a custom column mapping provided by the user.
     *
     * $columnMapping: { symbol: "My Symbol Col", direction: "Side", ... }
     * $options: { date_format: "Y-m-d H:i:s", direction_buy: "Long", direction_sell: "Short" }
     */
    public function buildCustomTemplate(array $columnMapping, array $options = []): array
    {
        $dateFormat = $options['date_format'] ?? 'd/m/Y H:i:s';
        $dirBuy = $options['direction_buy'] ?? 'Buy';
        $dirSell = $options['direction_sell'] ?? 'Sell';

        $columns = [];
        $requiredFields = ['symbol', 'direction', 'entry_price'];
        $optionalFields = ['closed_at', 'exit_price', 'size', 'pnl', 'opened_at', 'pips', 'comment'];

        foreach (array_merge($requiredFields, $optionalFields) as $field) {
            if (!isset($columnMapping[$field]) || $columnMapping[$field] === '') {
                if (in_array($field, $requiredFields)) {
                    throw new \App\Exceptions\ValidationException('import.error.missing_columns', $field);
                }
                continue;
            }

            $colDef = ['names' => [$columnMapping[$field]]];

            if ($field === 'direction') {
                $colDef['map'] = [
                    $dirBuy => 'BUY',
                    $dirSell => 'SELL',
                ];
            }

            if ($field === 'closed_at' || $field === 'opened_at') {
                $colDef['format'] = $dateFormat;
            }

            $columns[$field] = $colDef;
        }

        return [
            'broker' => 'custom',
            'label' => 'Custom',
            'file_types' => ['xlsx', 'csv'],
            'columns' => $columns,
            'grouping' => [
                'key' => ['symbol', 'direction', 'entry_price'],
                'partial_exits' => true,
            ],
        ];
    }

    /**
     * Load a template by broker key.
     */
    public function loadTemplate(string $broker): ?array
    {
        $file = __DIR__ . '/../../../config/import_templates/' . $broker . '.php';
        if (!file_exists($file)) {
            return null;
        }
        return require $file;
    }

    private function getExistingExternalIds(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT external_id FROM positions WHERE user_id = :user_id AND external_id IS NOT NULL"
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function resolveSymbol(int $userId, string $brokerSymbol, array $symbolMapping, ?string $broker, string $accountCurrency = 'EUR'): string
    {
        // First check explicit mapping from the confirm request
        if (isset($symbolMapping[$brokerSymbol])) {
            // Save alias for future imports
            $this->aliasRepo->upsert($userId, $brokerSymbol, $symbolMapping[$brokerSymbol], $broker);
            $resolved = $symbolMapping[$brokerSymbol];
        } elseif ($alias = $this->aliasRepo->findByBrokerSymbol($userId, $brokerSymbol, $broker)) {
            // Then check saved aliases
            $resolved = $alias['journal_symbol'];
        } else {
            // Fallback: use broker symbol as-is
            $resolved = $brokerSymbol;
        }

        // Auto-create symbol in user's assets if it doesn't exist
        $this->ensureSymbolExists($userId, $resolved, $accountCurrency);

        return $resolved;
    }

    private function ensureSymbolExists(int $userId, string $code, string $currency): void
    {
        if ($this->symbolRepo->findByUserAndCode($userId, $code)) {
            return;
        }

        $this->symbolRepo->create([
            'user_id' => $userId,
            'code' => $code,
            'name' => $code,
            'type' => SymbolType::OTHER->value,
            'point_value' => 1.0,
            'currency' => $currency,
        ]);
    }

    private function createImportedTrade(int $positionId, array $posData): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO trades (position_id, opened_at, closed_at, remaining_size, avg_exit_price, pnl, status, exit_type)
             VALUES (:position_id, :opened_at, :closed_at, :remaining_size, :avg_exit_price, :pnl, :status, :exit_type)"
        );
        $stmt->execute([
            'position_id' => $positionId,
            'opened_at' => $posData['opened_at'],
            'closed_at' => $posData['closed_at'],
            'remaining_size' => 0,
            'avg_exit_price' => $posData['avg_exit_price'],
            'pnl' => $posData['total_pnl'],
            'status' => TradeStatus::CLOSED->value,
            'exit_type' => ExitType::MANUAL->value,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createOpenImportedTrade(int $positionId, array $posData): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO trades (position_id, opened_at, remaining_size, status)
             VALUES (:position_id, :opened_at, :remaining_size, :status)"
        );
        $stmt->execute([
            'position_id' => $positionId,
            'opened_at' => $posData['opened_at'] ?? date('Y-m-d H:i:s'),
            'remaining_size' => $posData['total_size'],
            'status' => TradeStatus::OPEN->value,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createPartialExit(int $tradeId, array $exit): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO partial_exits (trade_id, exited_at, exit_price, size, exit_type, pnl)
             VALUES (:trade_id, :exited_at, :exit_price, :size, :exit_type, :pnl)"
        );
        $stmt->execute([
            'trade_id' => $tradeId,
            'exited_at' => $exit['closed_at'],
            'exit_price' => $exit['exit_price'],
            'size' => $exit['size'],
            'exit_type' => ExitType::MANUAL->value,
            'pnl' => $exit['pnl'],
        ]);
    }

    /**
     * Fill default values for optional fields in a normalized row.
     * Only size is defaulted. Missing closed_at/exit_price/pnl = trade is OPEN.
     */
    private function fillRowDefaults(array $row): array
    {
        // Default size to 1
        if (!isset($row['size']) || $row['size'] === null || $row['size'] === 0.0) {
            $row['size'] = 1.0;
        }

        return $row;
    }

    /**
     * Determine if a position should be imported as OPEN or CLOSED.
     */
    private function isOpenPosition(array $posData): bool
    {
        return !isset($posData['closed_at']) || $posData['closed_at'] === null;
    }

    /**
     * Attach custom field values from raw rows to grouped positions.
     * Uses the same grouping key to match positions back to their raw rows,
     * taking the first non-empty value per custom field per group.
     */
    private function attachCustomFieldValues(array &$positions, array $rawRows, array $normalizedRows, array $groupKey, array $customFieldsMapping): void
    {
        if (empty($customFieldsMapping)) {
            foreach ($positions as &$pos) {
                $pos['custom_fields'] = [];
            }
            return;
        }

        // Build lookup: groupKey → custom field values (first row wins)
        $cfByGroup = [];
        foreach ($rawRows as $i => $rawRow) {
            $norm = $normalizedRows[$i];
            $key = implode('|', array_map(fn($f) => (string) ($norm[$f] ?? ''), $groupKey));

            if (isset($cfByGroup[$key])) {
                continue; // first row wins
            }

            $cfValues = [];
            foreach ($customFieldsMapping as $fieldId => $headerName) {
                $value = $rawRow[$headerName] ?? null;
                if ($value !== null && $value !== '') {
                    $cfValues[] = [
                        'field_id' => (int) $fieldId,
                        'value' => (string) $value,
                    ];
                }
            }
            $cfByGroup[$key] = $cfValues;
        }

        // Attach to positions
        foreach ($positions as &$pos) {
            $key = implode('|', array_map(fn($f) => (string) ($pos[$f] ?? ''), $groupKey));
            $pos['custom_fields'] = $cfByGroup[$key] ?? [];
        }
    }
}
