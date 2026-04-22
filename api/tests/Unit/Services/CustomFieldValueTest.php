<?php

namespace Tests\Unit\Services;

use App\Exceptions\ValidationException;
use App\Repositories\CustomFieldDefinitionRepository;
use App\Repositories\CustomFieldValueRepository;
use App\Services\CustomFieldService;
use PHPUnit\Framework\TestCase;

class CustomFieldValueTest extends TestCase
{
    private CustomFieldService $service;
    private CustomFieldDefinitionRepository $defRepo;
    private CustomFieldValueRepository $valRepo;

    protected function setUp(): void
    {
        $this->defRepo = $this->createMock(CustomFieldDefinitionRepository::class);
        $this->valRepo = $this->createMock(CustomFieldValueRepository::class);
        $this->service = new CustomFieldService($this->defRepo, $this->valRepo);
    }

    private function makeDefinition(int $id, string $type, ?string $options = null): array
    {
        return [
            'id' => $id,
            'user_id' => 1,
            'name' => "Field $id",
            'field_type' => $type,
            'options' => $options,
            'is_active' => 1,
        ];
    }

    // ── BOOLEAN validation ───────────────────────────────────────

    public function testValidateBooleanAcceptsTrue(): void
    {
        $def = $this->makeDefinition(1, 'BOOLEAN');
        $this->defRepo->method('findById')->willReturn($def);
        $this->valRepo->expects($this->once())->method('saveForTrade');

        $this->service->validateAndSaveValues(1, 100, [
            ['field_id' => 1, 'value' => 'true'],
        ]);
    }

    public function testValidateBooleanAcceptsFalse(): void
    {
        $def = $this->makeDefinition(1, 'BOOLEAN');
        $this->defRepo->method('findById')->willReturn($def);
        $this->valRepo->expects($this->once())->method('saveForTrade');

        $this->service->validateAndSaveValues(1, 100, [
            ['field_id' => 1, 'value' => 'false'],
        ]);
    }

    public function testValidateBooleanRejectsInvalid(): void
    {
        $def = $this->makeDefinition(1, 'BOOLEAN');
        $this->defRepo->method('findById')->willReturn($def);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.invalid_boolean');

        $this->service->validateAndSaveValues(1, 100, [
            ['field_id' => 1, 'value' => 'maybe'],
        ]);
    }

    // ── TEXT validation ──────────────────────────────────────────

    public function testValidateTextAcceptsString(): void
    {
        $def = $this->makeDefinition(2, 'TEXT');
        $this->defRepo->method('findById')->willReturn($def);
        $this->valRepo->expects($this->once())->method('saveForTrade');

        $this->service->validateAndSaveValues(1, 100, [
            ['field_id' => 2, 'value' => 'Some text'],
        ]);
    }

    public function testValidateTextRejectsExcessiveLength(): void
    {
        $def = $this->makeDefinition(2, 'TEXT');
        $this->defRepo->method('findById')->willReturn($def);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.text_too_long');

        $this->service->validateAndSaveValues(1, 100, [
            ['field_id' => 2, 'value' => str_repeat('x', 5001)],
        ]);
    }

    // ── NUMBER validation ────────────────────────────────────────

    public function testValidateNumberAcceptsInteger(): void
    {
        $def = $this->makeDefinition(3, 'NUMBER');
        $this->defRepo->method('findById')->willReturn($def);
        $this->valRepo->expects($this->once())->method('saveForTrade');

        $this->service->validateAndSaveValues(1, 100, [
            ['field_id' => 3, 'value' => '42'],
        ]);
    }

    public function testValidateNumberAcceptsDecimal(): void
    {
        $def = $this->makeDefinition(3, 'NUMBER');
        $this->defRepo->method('findById')->willReturn($def);
        $this->valRepo->expects($this->once())->method('saveForTrade');

        $this->service->validateAndSaveValues(1, 100, [
            ['field_id' => 3, 'value' => '3.14'],
        ]);
    }

    public function testValidateNumberRejectsText(): void
    {
        $def = $this->makeDefinition(3, 'NUMBER');
        $this->defRepo->method('findById')->willReturn($def);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.invalid_number');

        $this->service->validateAndSaveValues(1, 100, [
            ['field_id' => 3, 'value' => 'not a number'],
        ]);
    }

    // ── SELECT validation ────────────────────────────────────────

    public function testValidateSelectAcceptsValidOption(): void
    {
        $def = $this->makeDefinition(4, 'SELECT', '["Good","Bad","Neutral"]');
        $this->defRepo->method('findById')->willReturn($def);
        $this->valRepo->expects($this->once())->method('saveForTrade');

        $this->service->validateAndSaveValues(1, 100, [
            ['field_id' => 4, 'value' => 'Good'],
        ]);
    }

    public function testValidateSelectRejectsInvalidOption(): void
    {
        $def = $this->makeDefinition(4, 'SELECT', '["Good","Bad","Neutral"]');
        $this->defRepo->method('findById')->willReturn($def);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.invalid_option');

        $this->service->validateAndSaveValues(1, 100, [
            ['field_id' => 4, 'value' => 'Excellent'],
        ]);
    }

    // ── Field ownership ──────────────────────────────────────────

    public function testValidateRejectsUnknownFieldId(): void
    {
        $this->defRepo->method('findById')->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.field_not_found');

        $this->service->validateAndSaveValues(1, 100, [
            ['field_id' => 999, 'value' => 'test'],
        ]);
    }

    public function testValidateRejectsInactiveField(): void
    {
        $def = $this->makeDefinition(1, 'TEXT');
        $def['is_active'] = 0;
        $this->defRepo->method('findById')->willReturn($def);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.field_inactive');

        $this->service->validateAndSaveValues(1, 100, [
            ['field_id' => 1, 'value' => 'test'],
        ]);
    }

    public function testValidateRejectsOtherUsersField(): void
    {
        $def = $this->makeDefinition(1, 'TEXT');
        $def['user_id'] = 2;
        $this->defRepo->method('findById')->willReturn($def);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.field_not_found');

        $this->service->validateAndSaveValues(1, 100, [
            ['field_id' => 1, 'value' => 'test'],
        ]);
    }

    // ── Empty / null ─────────────────────────────────────────────

    public function testCustomFieldsOptional(): void
    {
        $this->valRepo->expects($this->never())->method('saveForTrade');

        $this->service->validateAndSaveValues(1, 100, []);
    }

    public function testNullValueAccepted(): void
    {
        $def = $this->makeDefinition(1, 'TEXT');
        $this->defRepo->method('findById')->willReturn($def);
        $this->valRepo->expects($this->once())->method('saveForTrade');

        $this->service->validateAndSaveValues(1, 100, [
            ['field_id' => 1, 'value' => null],
        ]);
    }
}
