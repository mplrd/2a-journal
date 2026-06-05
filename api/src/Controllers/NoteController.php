<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\NoteService;

/**
 * Personal notebook endpoints (own notes only). Notes are created with their
 * images in one multipart request; field edits go through JSON PUT, while extra
 * images are added/removed via the dedicated attachment routes (keeps PHP off
 * the unreliable PUT-multipart path).
 */
class NoteController extends Controller
{
    public function __construct(private NoteService $service)
    {
    }

    public function index(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $filters = [];
        foreach (['category_id', 'pinned'] as $key) {
            $value = $request->getQuery($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        return $this->jsonSuccess($this->service->list($userId, $filters));
    }

    public function store(Request $request): Response
    {
        $this->guardUploadNotTruncated($request);
        $userId = (int) $request->getAttribute('user_id');
        $files = $this->normalizeUploadedFiles($request->getFile('attachments'));

        return $this->jsonSuccess($this->service->create($userId, $request->getBody(), $files), null, 201);
    }

    public function show(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $id = (int) $request->getRouteParam('id');

        return $this->jsonSuccess($this->service->get($userId, $id));
    }

    public function update(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $id = (int) $request->getRouteParam('id');

        return $this->jsonSuccess($this->service->update($userId, $id, $request->getBody()));
    }

    public function destroy(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $id = (int) $request->getRouteParam('id');
        $this->service->delete($userId, $id);

        return $this->jsonSuccess(['message_key' => 'notes.success.deleted']);
    }

    public function addAttachments(Request $request): Response
    {
        $this->guardUploadNotTruncated($request);
        $userId = (int) $request->getAttribute('user_id');
        $id = (int) $request->getRouteParam('id');
        $files = $this->normalizeUploadedFiles($request->getFile('attachments'));

        return $this->jsonSuccess($this->service->addAttachments($userId, $id, $files), null, 201);
    }

    public function destroyAttachment(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $id = (int) $request->getRouteParam('id');
        $attId = (int) $request->getRouteParam('attId');
        $this->service->deleteAttachment($userId, $id, $attId);

        return $this->jsonSuccess(['message_key' => 'notes.success.attachment_deleted']);
    }

    public function downloadAttachment(Request $request): never
    {
        $userId = (int) $request->getAttribute('user_id');
        $attId = (int) $request->getRouteParam('attId');
        $resolved = $this->service->getAttachmentForUser($userId, $attId);

        $attachment = $resolved['attachment'];
        // Strip quotes / control chars from the client-supplied name before it
        // lands in the Content-Disposition header (header-injection guard).
        $safeName = preg_replace('/["\r\n]+/', '', basename((string) $attachment['original_name']));
        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Disposition: inline; filename="' . $safeName . '"');
        header('Content-Length: ' . (string) ($attachment['size_bytes'] ?? filesize($resolved['path'])));
        header('X-Content-Type-Options: nosniff');
        readfile($resolved['path']);
        exit;
    }
}
