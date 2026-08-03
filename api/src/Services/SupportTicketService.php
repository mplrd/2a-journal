<?php

namespace App\Services;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\SupportTicketAttachmentRepository;
use App\Repositories\SupportTicketMessageRepository;
use App\Repositories\SupportTicketRepository;
use App\Repositories\UserRepository;

/**
 * Support module business logic: ticket creation, discussion thread, status &
 * priority management, attachment handling and the mail notifications that go
 * with each event. Controllers stay thin and delegate here.
 *
 * Email sending is best-effort (never blocks the request) and only happens when
 * an EmailService is wired in — same optional-dependency pattern as AuthService.
 */
class SupportTicketService
{
    /** Real-mime => extension whitelist for attachments (images only in v1). */
    public const ALLOWED_ATTACHMENT_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    public const MAX_ATTACHMENT_SIZE = 5 * 1024 * 1024; // 5 MB per file
    public const MAX_ATTACHMENTS = 5;
    private const ATTACHMENT_SUBDIR = 'tickets';
    private const SUBJECT_MAX = 200;
    private const DETAIL_FIELD_MAX = 5000;

    public function __construct(
        private SupportTicketRepository $ticketRepo,
        private SupportTicketMessageRepository $messageRepo,
        private SupportTicketAttachmentRepository $attachmentRepo,
        private FileUploadService $fileUpload,
        private UserRepository $userRepo,
        private ?EmailService $emailService = null,
    ) {
    }

    // ── User-facing ─────────────────────────────────────────────

    public function createTicket(int $userId, array $data, array $files = []): array
    {
        $type = $this->validateType($data['type'] ?? null);
        $subject = $this->validateSubject($data['subject'] ?? null);
        $body = $this->validateBody($data['body'] ?? null);
        $details = $this->normalizeDetails($type, $data['details'] ?? null);
        $this->validateAttachmentCount($files);

        $ticket = $this->ticketRepo->create([
            'user_id' => $userId,
            'type' => $type->value,
            'subject' => $subject,
            'details' => $details,
            'status' => TicketStatus::OPEN->value,
            'priority' => TicketPriority::NORMAL->value,
        ]);

        $message = $this->messageRepo->create([
            'ticket_id' => $ticket['id'],
            'author_id' => $userId,
            'author_is_admin' => false,
            'body' => $body,
        ]);
        $this->storeAttachments((int) $ticket['id'], (int) $message['id'], $files);

        $this->notifyAdmins(
            fn ($email, $locale) => $this->emailService->sendTicketCreatedToAdmin($email, $ticket, $locale)
        );

        return $this->getDetailForUser($userId, (int) $ticket['id']);
    }

    public function replyAsUser(int $userId, int $ticketId, ?string $body, array $files = []): array
    {
        $ticket = $this->ticketRepo->findByIdForUser($ticketId, $userId);
        if ($ticket === null) {
            throw new NotFoundException('support.error.not_found');
        }
        if ($ticket['status'] === TicketStatus::CLOSED->value) {
            throw new ValidationException('support.error.ticket_closed');
        }
        $body = $this->validateBody($body);
        $this->validateAttachmentCount($files);

        $message = $this->messageRepo->create([
            'ticket_id' => $ticketId,
            'author_id' => $userId,
            'author_is_admin' => false,
            'body' => $body,
        ]);
        $this->storeAttachments($ticketId, (int) $message['id'], $files);
        $this->ticketRepo->touch($ticketId);

        $this->notifyAdmins(
            fn ($email, $locale) => $this->emailService->sendTicketReplyEmail($email, $ticket, $locale, false)
        );

        return $this->getDetailForUser($userId, $ticketId);
    }

    public function listForUser(int $userId, array $filters): array
    {
        [$page, $perPage, $offset] = $this->paginationFrom($filters);
        $result = $this->ticketRepo->findAllByUserId($userId, $this->listFilters($filters), $perPage, $offset);

        return $this->withMeta($result, $page, $perPage);
    }

    public function getDetailForUser(int $userId, int $ticketId): array
    {
        $ticket = $this->ticketRepo->findByIdForUser($ticketId, $userId);
        if ($ticket === null) {
            throw new NotFoundException('support.error.not_found');
        }

        return $this->assembleDetail($ticket);
    }

    /**
     * Resolve an attachment for download, enforcing that it belongs to a ticket
     * owned by $userId. Returns the DB row plus its absolute path on disk.
     */
    public function getAttachmentForUser(int $userId, int $attachmentId): array
    {
        $attachment = $this->attachmentRepo->findById($attachmentId);
        if ($attachment === null) {
            throw new NotFoundException('support.error.attachment_not_found');
        }
        $ticket = $this->ticketRepo->findByIdForUser((int) $attachment['ticket_id'], $userId);
        if ($ticket === null) {
            throw new NotFoundException('support.error.attachment_not_found');
        }

        return $this->resolveAttachment($attachment);
    }

    // ── Admin-facing ────────────────────────────────────────────

    public function replyAsAdmin(int $adminId, int $ticketId, ?string $body, array $files = []): array
    {
        $ticket = $this->requireTicket($ticketId);
        $body = $this->validateBody($body);
        $this->validateAttachmentCount($files);

        $message = $this->messageRepo->create([
            'ticket_id' => $ticketId,
            'author_id' => $adminId,
            'author_is_admin' => true,
            'body' => $body,
        ]);
        $this->storeAttachments($ticketId, (int) $message['id'], $files);
        $this->ticketRepo->touch($ticketId);

        $this->notifyCreator(
            (int) $ticket['user_id'],
            fn ($email, $locale) => $this->emailService->sendTicketReplyEmail($email, $ticket, $locale, true)
        );

        return $this->getDetailForAdmin($ticketId);
    }

    public function changeStatus(int $adminId, int $ticketId, ?string $status): array
    {
        $ticket = $this->requireTicket($ticketId);
        $newStatus = TicketStatus::tryFrom((string) $status);
        if ($newStatus === null) {
            throw new ValidationException('support.error.invalid_status', 'status');
        }

        $oldStatus = $ticket['status'];
        $closedAt = $newStatus === TicketStatus::CLOSED ? date('Y-m-d H:i:s') : null;
        $this->ticketRepo->updateStatus($ticketId, $newStatus->value, $closedAt);

        if ($newStatus->value !== $oldStatus) {
            $this->notifyCreator(
                (int) $ticket['user_id'],
                fn ($email, $locale) => $this->emailService->sendTicketStatusChangedEmail(
                    $email,
                    $ticket,
                    $oldStatus,
                    $newStatus->value,
                    $locale
                )
            );
        }

        return $this->getDetailForAdmin($ticketId);
    }

    public function changePriority(int $adminId, int $ticketId, ?string $priority): array
    {
        $this->requireTicket($ticketId);
        $newPriority = TicketPriority::tryFrom((string) $priority);
        if ($newPriority === null) {
            throw new ValidationException('support.error.invalid_priority', 'priority');
        }
        $this->ticketRepo->updatePriority($ticketId, $newPriority->value);

        return $this->getDetailForAdmin($ticketId);
    }

    /**
     * Reclassify a ticket between SUPPORT / BUG / FEATURE. Reporters are not
     * consistent about the type they pick at creation, so triage needs to fix
     * it afterwards. Silent on purpose (no e-mail): it is an internal
     * bookkeeping change, unlike a status transition.
     */
    public function changeType(int $adminId, int $ticketId, ?string $type): array
    {
        $this->requireTicket($ticketId);
        $newType = $this->validateType($type);
        $this->ticketRepo->updateType($ticketId, $newType->value);

        return $this->getDetailForAdmin($ticketId);
    }

    public function listForAdmin(array $filters): array
    {
        [$page, $perPage, $offset] = $this->paginationFrom($filters);
        $result = $this->ticketRepo->findAllAdmin($this->listFilters($filters, true), $perPage, $offset);

        return $this->withMeta($result, $page, $perPage);
    }

    public function getDetailForAdmin(int $ticketId): array
    {
        return $this->assembleDetail($this->requireTicket($ticketId));
    }

    public function getAttachmentForAdmin(int $attachmentId): array
    {
        $attachment = $this->attachmentRepo->findById($attachmentId);
        if ($attachment === null) {
            throw new NotFoundException('support.error.attachment_not_found');
        }

        return $this->resolveAttachment($attachment);
    }

    // ── Internals ───────────────────────────────────────────────

    private function requireTicket(int $ticketId): array
    {
        $ticket = $this->ticketRepo->findById($ticketId);
        if ($ticket === null) {
            throw new NotFoundException('support.error.not_found');
        }

        return $ticket;
    }

    private function assembleDetail(array $ticket): array
    {
        $messages = $this->messageRepo->findByTicketId((int) $ticket['id']);
        $attachments = $this->attachmentRepo->findByTicketId((int) $ticket['id']);

        $byMessage = [];
        foreach ($attachments as $a) {
            $byMessage[(int) $a['message_id']][] = $a;
        }
        foreach ($messages as &$m) {
            $m['attachments'] = $byMessage[(int) $m['id']] ?? [];
        }
        unset($m);

        $ticket['messages'] = $messages;
        $ticket['attachments'] = $attachments;
        $ticket['details'] = $this->decodeDetails($ticket['details'] ?? null);

        return $ticket;
    }

    /**
     * Keep only the structured detail keys whitelisted for the ticket type,
     * trim them, cap length, and drop empties. Returns null when nothing
     * usable remains (e.g. SUPPORT, or a BUG with all fields blank) so the
     * column stays NULL rather than an empty object.
     */
    private function normalizeDetails(TicketType $type, mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $out = [];
        foreach ($type->detailKeys() as $key) {
            $value = trim((string) ($raw[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $out[$key] = mb_substr($value, 0, self::DETAIL_FIELD_MAX);
        }

        return $out === [] ? null : $out;
    }

    /** Decode the JSON `details` column back to an array (or null) for output. */
    private function decodeDetails(mixed $stored): ?array
    {
        if (is_array($stored)) {
            return $stored;
        }
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        $decoded = json_decode($stored, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array{0:string, 1:array, 2:int} stored row + absolute path */
    private function resolveAttachment(array $attachment): array
    {
        $path = $this->fileUpload->absolutePath($attachment['stored_path']);
        if (!is_file($path)) {
            throw new NotFoundException('support.error.attachment_not_found');
        }

        return ['path' => $path, 'attachment' => $attachment];
    }

    private function storeAttachments(int $ticketId, int $messageId, array $files): void
    {
        foreach ($files as $file) {
            $meta = $this->fileUpload->store(
                $file,
                self::ATTACHMENT_SUBDIR,
                self::ALLOWED_ATTACHMENT_TYPES,
                self::MAX_ATTACHMENT_SIZE,
                'attachments'
            );
            $this->attachmentRepo->create([
                'ticket_id' => $ticketId,
                'message_id' => $messageId,
                'stored_path' => $meta['stored_path'],
                'original_name' => $meta['original_name'],
                'mime_type' => $meta['mime_type'],
                'size_bytes' => $meta['size_bytes'],
            ]);
        }
    }

    private function notifyAdmins(callable $send): void
    {
        if ($this->emailService === null) {
            return;
        }
        foreach ($this->userRepo->findAdmins() as $admin) {
            $this->safeSend(fn () => $send($admin['email'], $admin['locale'] ?? 'en'));
        }
    }

    private function notifyCreator(int $userId, callable $send): void
    {
        if ($this->emailService === null) {
            return;
        }
        $user = $this->userRepo->findById($userId);
        if ($user === null || empty($user['email'])) {
            return;
        }
        $this->safeSend(fn () => $send($user['email'], $user['locale'] ?? 'en'));
    }

    private function safeSend(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            error_log('[SupportTicketService] email send failed: ' . $e->getMessage());
        }
    }

    private function validateType(?string $type): TicketType
    {
        $value = TicketType::tryFrom((string) $type);
        if ($value === null) {
            throw new ValidationException('support.error.invalid_type', 'type');
        }

        return $value;
    }

    private function validateSubject(?string $subject): string
    {
        $subject = trim((string) $subject);
        if ($subject === '') {
            throw new ValidationException('support.error.subject_required', 'subject');
        }
        if (mb_strlen($subject) > self::SUBJECT_MAX) {
            throw new ValidationException('support.error.subject_too_long', 'subject');
        }

        return $subject;
    }

    private function validateBody(?string $body): string
    {
        $body = trim((string) $body);
        if ($body === '') {
            throw new ValidationException('support.error.body_required', 'body');
        }

        return $body;
    }

    private function validateAttachmentCount(array $files): void
    {
        if (count($files) > self::MAX_ATTACHMENTS) {
            throw new ValidationException('support.error.too_many_attachments', 'attachments');
        }
    }

    /** @return array{0:int,1:int,2:int} page, perPage, offset */
    private function paginationFrom(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));

        return [$page, $perPage, ($page - 1) * $perPage];
    }

    private function listFilters(array $filters, bool $admin = false): array
    {
        $out = [];
        foreach (['type', 'status', 'priority'] as $key) {
            if (!empty($filters[$key])) {
                $out[$key] = $filters[$key];
            }
        }
        if ($admin && !empty($filters['search'])) {
            $out['search'] = $filters['search'];
        }

        return $out;
    }

    private function withMeta(array $result, int $page, int $perPage): array
    {
        $total = $result['total'];

        return [
            'data' => $result['items'],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }
}
