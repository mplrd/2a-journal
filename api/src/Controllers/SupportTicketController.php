<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Services\SupportTicketService;

/**
 * User-facing support endpoints (own tickets only). Mounted behind
 * AuthMiddleware WITHOUT the subscription gate — support must stay reachable
 * for every authenticated user, including those without an active plan.
 */
class SupportTicketController extends Controller
{
    public function __construct(private SupportTicketService $service)
    {
    }

    public function index(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $filters = [];
        foreach (['type', 'status', 'page', 'per_page'] as $key) {
            $value = $request->getQuery($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        $result = $this->service->listForUser($userId, $filters);

        return $this->jsonSuccess($result['data'], $result['meta']);
    }

    public function store(Request $request): Response
    {
        $this->guardUploadNotTruncated($request);
        $userId = (int) $request->getAttribute('user_id');
        $files = $this->normalizeUploadedFiles($request->getFile('attachments'));
        $detail = $this->service->createTicket($userId, $request->getBody(), $files);

        return $this->jsonSuccess($detail, null, 201);
    }

    public function show(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $ticketId = (int) $request->getRouteParam('id');

        return $this->jsonSuccess($this->service->getDetailForUser($userId, $ticketId));
    }

    public function reply(Request $request): Response
    {
        $this->guardUploadNotTruncated($request);
        $userId = (int) $request->getAttribute('user_id');
        $ticketId = (int) $request->getRouteParam('id');
        $files = $this->normalizeUploadedFiles($request->getFile('attachments'));
        $detail = $this->service->replyAsUser($userId, $ticketId, $request->getBody('body'), $files);

        return $this->jsonSuccess($detail, null, 201);
    }

    public function downloadAttachment(Request $request): never
    {
        $userId = (int) $request->getAttribute('user_id');
        $attachmentId = (int) $request->getRouteParam('attId');
        $resolved = $this->service->getAttachmentForUser($userId, $attachmentId);

        $this->streamAttachment($resolved);
    }

    private function streamAttachment(array $resolved): never
    {
        $attachment = $resolved['attachment'];
        // Strip quotes / control chars from the client-supplied name before it
        // lands in the Content-Disposition header (header-injection guard).
        $safeName = preg_replace('/[\"\r\n]+/', '', basename((string) $attachment['original_name']));
        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Disposition: inline; filename="' . $safeName . '"');
        header('Content-Length: ' . (string) ($attachment['size_bytes'] ?? filesize($resolved['path'])));
        header('X-Content-Type-Options: nosniff');
        readfile($resolved['path']);
        exit;
    }
}
