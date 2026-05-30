<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\SupportTicketService;

/**
 * Admin BO support endpoints (all tickets). Mounted behind AuthMiddleware +
 * RequireAdminMiddleware in config/routes.php.
 */
class AdminSupportTicketController extends Controller
{
    public function __construct(private SupportTicketService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = [];
        foreach (['type', 'status', 'priority', 'search', 'page', 'per_page'] as $key) {
            $value = $request->getQuery($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        $result = $this->service->listForAdmin($filters);

        return $this->jsonSuccess($result['data'], $result['meta']);
    }

    public function show(Request $request): Response
    {
        $ticketId = (int) $request->getRouteParam('id');

        return $this->jsonSuccess($this->service->getDetailForAdmin($ticketId));
    }

    public function reply(Request $request): Response
    {
        $adminId = (int) $request->getAttribute('user_id');
        $ticketId = (int) $request->getRouteParam('id');
        $files = $this->normalizeUploadedFiles($request->getFile('attachments'));
        $detail = $this->service->replyAsAdmin($adminId, $ticketId, $request->getBody('body'), $files);

        return $this->jsonSuccess($detail, null, 201);
    }

    public function updateStatus(Request $request): Response
    {
        $adminId = (int) $request->getAttribute('user_id');
        $ticketId = (int) $request->getRouteParam('id');
        $detail = $this->service->changeStatus($adminId, $ticketId, $request->getBody('status'));

        return $this->jsonSuccess($detail);
    }

    public function updatePriority(Request $request): Response
    {
        $adminId = (int) $request->getAttribute('user_id');
        $ticketId = (int) $request->getRouteParam('id');
        $detail = $this->service->changePriority($adminId, $ticketId, $request->getBody('priority'));

        return $this->jsonSuccess($detail);
    }

    public function downloadAttachment(Request $request): never
    {
        $attachmentId = (int) $request->getRouteParam('attId');
        $resolved = $this->service->getAttachmentForAdmin($attachmentId);

        $attachment = $resolved['attachment'];
        $safeName = preg_replace('/[\"\r\n]+/', '', basename((string) $attachment['original_name']));
        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Disposition: inline; filename="' . $safeName . '"');
        header('Content-Length: ' . (string) ($attachment['size_bytes'] ?? filesize($resolved['path'])));
        header('X-Content-Type-Options: nosniff');
        readfile($resolved['path']);
        exit;
    }
}
