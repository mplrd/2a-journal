<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AccountService;
use App\Services\DrawdownService;
use App\Services\Export\AccountExportService;
use App\Services\Export\AccountExportXlsxWriter;

class AccountController extends Controller
{
    private AccountService $accountService;
    private ?DrawdownService $drawdownService;
    private ?AccountExportService $exportService;
    private ?AccountExportXlsxWriter $xlsxWriter;

    public function __construct(
        AccountService $accountService,
        ?DrawdownService $drawdownService = null,
        ?AccountExportService $exportService = null,
        ?AccountExportXlsxWriter $xlsxWriter = null,
    ) {
        $this->accountService = $accountService;
        $this->drawdownService = $drawdownService;
        $this->exportService = $exportService;
        $this->xlsxWriter = $xlsxWriter;
    }

    public function index(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $params = [];
        foreach (['page', 'per_page'] as $key) {
            $value = $request->getQuery($key);
            if ($value !== null && $value !== '') {
                $params[$key] = $value;
            }
        }

        $result = $this->accountService->list($userId, $params);

        return $this->jsonSuccess($result['data'], $result['meta']);
    }

    public function store(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $account = $this->accountService->create($userId, $request->getBody());

        return $this->jsonSuccess($account, null, 201);
    }

    public function show(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int)$request->getRouteParam('id');
        $account = $this->accountService->get($userId, $accountId);

        return $this->jsonSuccess($account);
    }

    public function update(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int)$request->getRouteParam('id');
        $account = $this->accountService->update($userId, $accountId, $request->getBody());

        return $this->jsonSuccess($account);
    }

    public function destroy(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int)$request->getRouteParam('id');
        $this->accountService->delete($userId, $accountId);

        return $this->jsonSuccess(['message_key' => 'accounts.success.deleted']);
    }

    /**
     * GET /accounts/dd-status — DD usage + alert flags for every PF (or
     * non-PF with DD configured) account. Used by the dashboard banner.
     */
    public function ddStatus(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $statuses = $this->drawdownService !== null
            ? $this->drawdownService->getStatusForUser($userId)
            : [];

        return $this->jsonSuccess($statuses);
    }

    /**
     * GET /accounts/{id}/export — the account and its closed trades as an
     * .xlsx file (tabs "Account" and "Trades").
     */
    public function export(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int)$request->getRouteParam('id');
        $export = $this->exportService->build($userId, $accountId);

        return Response::download(
            $this->xlsxWriter->write($export['sheets']),
            AccountExportXlsxWriter::MIME_TYPE,
            $export['filename']
        );
    }
}
