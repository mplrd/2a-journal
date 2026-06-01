<?php

namespace Tests\Unit\Services;

use App\Enums\TicketStatus;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\SupportTicketAttachmentRepository;
use App\Repositories\SupportTicketMessageRepository;
use App\Repositories\SupportTicketRepository;
use App\Repositories\UserRepository;
use App\Services\EmailService;
use App\Services\FileUploadService;
use App\Services\SupportTicketService;
use PHPUnit\Framework\TestCase;

class SupportTicketServiceTest extends TestCase
{
    private SupportTicketRepository $ticketRepo;
    private SupportTicketMessageRepository $messageRepo;
    private SupportTicketAttachmentRepository $attachmentRepo;
    private FileUploadService $fileUpload;
    private UserRepository $userRepo;
    private EmailService $email;
    private SupportTicketService $service;

    protected function setUp(): void
    {
        $this->ticketRepo = $this->createMock(SupportTicketRepository::class);
        $this->messageRepo = $this->createMock(SupportTicketMessageRepository::class);
        $this->attachmentRepo = $this->createMock(SupportTicketAttachmentRepository::class);
        $this->fileUpload = $this->createMock(FileUploadService::class);
        $this->userRepo = $this->createMock(UserRepository::class);
        $this->email = $this->createMock(EmailService::class);

        $this->service = new SupportTicketService(
            $this->ticketRepo,
            $this->messageRepo,
            $this->attachmentRepo,
            $this->fileUpload,
            $this->userRepo,
            $this->email,
        );
    }

    private function fakeTicket(array $overrides = []): array
    {
        return array_merge([
            'id' => 7,
            'user_id' => 42,
            'type' => 'BUG',
            'status' => 'OPEN',
            'priority' => 'NORMAL',
            'subject' => 'It broke',
            'created_at' => '2026-05-30 10:00:00',
            'updated_at' => '2026-05-30 10:00:00',
            'closed_at' => null,
        ], $overrides);
    }

    public function testCreateTicketNotifiesEveryAdmin(): void
    {
        $this->ticketRepo->method('create')->willReturn($this->fakeTicket());
        $this->ticketRepo->method('findByIdForUser')->willReturn($this->fakeTicket());
        $this->messageRepo->method('create')->willReturn(['id' => 100]);
        $this->messageRepo->method('findByTicketId')->willReturn([]);
        $this->attachmentRepo->method('findByTicketId')->willReturn([]);
        $this->userRepo->method('findAdmins')->willReturn([
            ['id' => 1, 'email' => 'a@x.com', 'locale' => 'fr'],
            ['id' => 2, 'email' => 'b@x.com', 'locale' => 'en'],
        ]);

        $this->email->expects($this->exactly(2))->method('sendTicketCreatedToAdmin');

        $detail = $this->service->createTicket(42, ['type' => 'BUG', 'subject' => 'It broke', 'body' => 'details']);
        $this->assertSame(7, $detail['id']);
    }

    public function testCreateTicketRejectsInvalidType(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->createTicket(42, ['type' => 'WAT', 'subject' => 's', 'body' => 'b']);
    }

    public function testCreateBugKeepsOnlyWhitelistedDetailKeys(): void
    {
        $captured = null;
        $this->ticketRepo->method('create')->willReturnCallback(function (array $data) use (&$captured) {
            $captured = $data;
            return $this->fakeTicket();
        });
        $this->ticketRepo->method('findByIdForUser')->willReturn($this->fakeTicket());
        $this->messageRepo->method('create')->willReturn(['id' => 1]);
        $this->messageRepo->method('findByTicketId')->willReturn([]);
        $this->attachmentRepo->method('findByTicketId')->willReturn([]);

        $this->service->createTicket(42, [
            'type' => 'BUG',
            'subject' => 'It broke',
            'body' => 'description',
            'details' => [
                'expected_behavior' => '  should open  ',
                'reproduction_steps' => 'click X',
                'evil_key' => 'dropped',
            ],
        ]);

        $this->assertIsArray($captured['details']);
        $this->assertSame(['expected_behavior', 'reproduction_steps'], array_keys($captured['details']));
        $this->assertSame('should open', $captured['details']['expected_behavior']); // trimmed
        $this->assertArrayNotHasKey('evil_key', $captured['details']);
    }

    public function testCreateFeatureKeepsBenefitAndImaginedSolution(): void
    {
        $captured = null;
        $this->ticketRepo->method('create')->willReturnCallback(function (array $data) use (&$captured) {
            $captured = $data;
            return $this->fakeTicket(['type' => 'FEATURE']);
        });
        $this->ticketRepo->method('findByIdForUser')->willReturn($this->fakeTicket(['type' => 'FEATURE']));
        $this->messageRepo->method('create')->willReturn(['id' => 1]);
        $this->messageRepo->method('findByTicketId')->willReturn([]);
        $this->attachmentRepo->method('findByTicketId')->willReturn([]);

        $this->service->createTicket(42, [
            'type' => 'FEATURE',
            'subject' => 'Export',
            'body' => 'I need to export',
            'details' => ['benefit' => 'save time', 'imagined_solution' => 'a button', 'expected_behavior' => 'nope'],
        ]);

        $this->assertSame(['benefit', 'imagined_solution'], array_keys($captured['details']));
    }

    public function testCreateSupportIgnoresDetails(): void
    {
        $captured = null;
        $this->ticketRepo->method('create')->willReturnCallback(function (array $data) use (&$captured) {
            $captured = $data;
            return $this->fakeTicket(['type' => 'SUPPORT']);
        });
        $this->ticketRepo->method('findByIdForUser')->willReturn($this->fakeTicket(['type' => 'SUPPORT']));
        $this->messageRepo->method('create')->willReturn(['id' => 1]);
        $this->messageRepo->method('findByTicketId')->willReturn([]);
        $this->attachmentRepo->method('findByTicketId')->willReturn([]);

        $this->service->createTicket(42, [
            'type' => 'SUPPORT',
            'subject' => 'Question',
            'body' => 'how?',
            'details' => ['expected_behavior' => 'x', 'benefit' => 'y'],
        ]);

        $this->assertNull($captured['details']);
    }

    public function testCreateBugWithAllEmptyDetailsStoresNull(): void
    {
        $captured = null;
        $this->ticketRepo->method('create')->willReturnCallback(function (array $data) use (&$captured) {
            $captured = $data;
            return $this->fakeTicket();
        });
        $this->ticketRepo->method('findByIdForUser')->willReturn($this->fakeTicket());
        $this->messageRepo->method('create')->willReturn(['id' => 1]);
        $this->messageRepo->method('findByTicketId')->willReturn([]);
        $this->attachmentRepo->method('findByTicketId')->willReturn([]);

        $this->service->createTicket(42, [
            'type' => 'BUG',
            'subject' => 'It broke',
            'body' => 'description',
            'details' => ['expected_behavior' => '   ', 'reproduction_steps' => ''],
        ]);

        $this->assertNull($captured['details']);
    }

    public function testAssembleDetailDecodesJsonDetails(): void
    {
        $this->ticketRepo->method('findByIdForUser')->willReturn($this->fakeTicket([
            'details' => json_encode(['expected_behavior' => 'should open', 'reproduction_steps' => 'click']),
        ]));
        $this->messageRepo->method('findByTicketId')->willReturn([]);
        $this->attachmentRepo->method('findByTicketId')->willReturn([]);

        $detail = $this->service->getDetailForUser(42, 7);

        $this->assertIsArray($detail['details']);
        $this->assertSame('should open', $detail['details']['expected_behavior']);
    }

    public function testCreateTicketRejectsEmptyBody(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->createTicket(42, ['type' => 'BUG', 'subject' => 's', 'body' => '   ']);
    }

    public function testReplyAsUserOnClosedTicketIsRejected(): void
    {
        $this->ticketRepo->method('findByIdForUser')->willReturn($this->fakeTicket(['status' => 'CLOSED']));

        $this->expectException(ValidationException::class);
        $this->service->replyAsUser(42, 7, 'please reopen');
    }

    public function testReplyAsUserNotifiesAdmins(): void
    {
        $this->ticketRepo->method('findByIdForUser')->willReturn($this->fakeTicket());
        $this->messageRepo->method('create')->willReturn(['id' => 101]);
        $this->messageRepo->method('findByTicketId')->willReturn([]);
        $this->attachmentRepo->method('findByTicketId')->willReturn([]);
        $this->userRepo->method('findAdmins')->willReturn([
            ['id' => 1, 'email' => 'a@x.com', 'locale' => 'fr'],
        ]);

        $this->email->expects($this->once())
            ->method('sendTicketReplyEmail')
            ->with('a@x.com', $this->anything(), 'fr', false);

        $this->service->replyAsUser(42, 7, 'more info');
    }

    public function testReplyAsAdminNotifiesCreator(): void
    {
        $this->ticketRepo->method('findById')->willReturn($this->fakeTicket());
        $this->messageRepo->method('create')->willReturn(['id' => 102]);
        $this->messageRepo->method('findByTicketId')->willReturn([]);
        $this->attachmentRepo->method('findByTicketId')->willReturn([]);
        $this->userRepo->method('findById')->with(42)->willReturn([
            'id' => 42, 'email' => 'owner@x.com', 'locale' => 'en',
        ]);

        $this->email->expects($this->once())
            ->method('sendTicketReplyEmail')
            ->with('owner@x.com', $this->anything(), 'en', true);

        $this->service->replyAsAdmin(1, 7, 'we are on it');
    }

    public function testChangeStatusStampsClosedAtAndNotifiesCreator(): void
    {
        $this->ticketRepo->method('findById')->willReturn($this->fakeTicket(['status' => 'OPEN']));
        $this->messageRepo->method('findByTicketId')->willReturn([]);
        $this->attachmentRepo->method('findByTicketId')->willReturn([]);
        $this->userRepo->method('findById')->willReturn(['id' => 42, 'email' => 'owner@x.com', 'locale' => 'fr']);

        $this->ticketRepo->expects($this->once())
            ->method('updateStatus')
            ->with(7, TicketStatus::CLOSED->value, $this->isType('string'));
        $this->email->expects($this->once())->method('sendTicketStatusChangedEmail');

        $this->service->changeStatus(1, 7, 'CLOSED');
    }

    public function testChangeStatusRejectsInvalidStatus(): void
    {
        $this->ticketRepo->method('findById')->willReturn($this->fakeTicket());

        $this->expectException(ValidationException::class);
        $this->service->changeStatus(1, 7, 'NOPE');
    }

    public function testChangePriorityRejectsInvalidValue(): void
    {
        $this->ticketRepo->method('findById')->willReturn($this->fakeTicket());

        $this->expectException(ValidationException::class);
        $this->service->changePriority(1, 7, 'URGENT');
    }

    public function testGetDetailForUserNotFound(): void
    {
        $this->ticketRepo->method('findByIdForUser')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->getDetailForUser(42, 999);
    }

    public function testGetAttachmentForUserEnforcesOwnership(): void
    {
        $this->attachmentRepo->method('findById')->willReturn([
            'id' => 5, 'ticket_id' => 7, 'stored_path' => 'tickets/x.png',
        ]);
        $this->ticketRepo->method('findByIdForUser')->willReturn(null); // not owner

        $this->expectException(NotFoundException::class);
        $this->service->getAttachmentForUser(999, 5);
    }
}
