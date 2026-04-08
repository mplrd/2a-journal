<?php

namespace Tests\Unit\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\CustomFieldDefinitionRepository;
use App\Services\CustomFieldService;
use PHPUnit\Framework\TestCase;

class CustomFieldServiceTest extends TestCase
{
    private CustomFieldService $service;
    private CustomFieldDefinitionRepository $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(CustomFieldDefinitionRepository::class);
        $this->service = new CustomFieldService($this->repo);
    }

    // ── List ─────────────────────────────────────────────────────

    public function testListReturnsUserDefinitions(): void
    {
        $definitions = [
            ['id' => 1, 'name' => 'Confident', 'field_type' => 'BOOLEAN', 'sort_order' => 0],
            ['id' => 2, 'name' => 'Score', 'field_type' => 'NUMBER', 'sort_order' => 1],
        ];
        $this->repo->method('findAllByUserId')->with(1)->willReturn($definitions);

        $result = $this->service->list(1);

        $this->assertSame($definitions, $result);
    }

    // ── Create validation ────────────────────────────────────────

    public function testCreateThrowsWhenNameMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.field_required');

        $this->service->create(1, ['name' => '', 'field_type' => 'TEXT']);
    }

    public function testCreateThrowsWhenNameIsWhitespaceOnly(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.field_required');

        $this->service->create(1, ['name' => '   ', 'field_type' => 'TEXT']);
    }

    public function testCreateThrowsWhenNameTooLong(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.name_too_long');

        $this->service->create(1, ['name' => str_repeat('x', 101), 'field_type' => 'TEXT']);
    }

    public function testCreateThrowsWhenFieldTypeMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.field_required');

        $this->service->create(1, ['name' => 'Test']);
    }

    public function testCreateThrowsWhenFieldTypeInvalid(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.invalid_field_type');

        $this->service->create(1, ['name' => 'Test', 'field_type' => 'INVALID']);
    }

    public function testCreateThrowsWhenDuplicateName(): void
    {
        $this->repo->method('findByUserAndName')
            ->with(1, 'Confident')
            ->willReturn(['id' => 99, 'name' => 'Confident']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.duplicate_name');

        $this->service->create(1, ['name' => 'Confident', 'field_type' => 'BOOLEAN']);
    }

    public function testCreateThrowsWhenSelectMissingOptions(): void
    {
        $this->repo->method('findByUserAndName')->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.select_options_required');

        $this->service->create(1, ['name' => 'Mood', 'field_type' => 'SELECT']);
    }

    public function testCreateThrowsWhenSelectOptionsEmpty(): void
    {
        $this->repo->method('findByUserAndName')->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.select_options_required');

        $this->service->create(1, ['name' => 'Mood', 'field_type' => 'SELECT', 'options' => []]);
    }

    public function testCreateThrowsWhenSelectOptionsNotArray(): void
    {
        $this->repo->method('findByUserAndName')->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.select_options_required');

        $this->service->create(1, ['name' => 'Mood', 'field_type' => 'SELECT', 'options' => 'bad']);
    }

    public function testCreateTrimsName(): void
    {
        $expected = ['id' => 1, 'name' => 'Confident', 'field_type' => 'BOOLEAN', 'user_id' => 1];
        $this->repo->method('findByUserAndName')->willReturn(null);
        $this->repo->expects($this->once())
            ->method('create')
            ->with($this->callback(fn($data) => $data['name'] === 'Confident'))
            ->willReturn($expected);

        $result = $this->service->create(1, ['name' => '  Confident  ', 'field_type' => 'BOOLEAN']);

        $this->assertSame($expected, $result);
    }

    public function testCreateSuccessBoolean(): void
    {
        $expected = ['id' => 1, 'name' => 'Confident', 'field_type' => 'BOOLEAN', 'user_id' => 1];
        $this->repo->method('findByUserAndName')->willReturn(null);
        $this->repo->method('create')->willReturn($expected);

        $result = $this->service->create(1, ['name' => 'Confident', 'field_type' => 'BOOLEAN']);

        $this->assertSame($expected, $result);
    }

    public function testCreateSuccessText(): void
    {
        $expected = ['id' => 2, 'name' => 'Notes', 'field_type' => 'TEXT', 'user_id' => 1];
        $this->repo->method('findByUserAndName')->willReturn(null);
        $this->repo->method('create')->willReturn($expected);

        $result = $this->service->create(1, ['name' => 'Notes', 'field_type' => 'TEXT']);

        $this->assertSame($expected, $result);
    }

    public function testCreateSuccessNumber(): void
    {
        $expected = ['id' => 3, 'name' => 'Score', 'field_type' => 'NUMBER', 'user_id' => 1];
        $this->repo->method('findByUserAndName')->willReturn(null);
        $this->repo->method('create')->willReturn($expected);

        $result = $this->service->create(1, ['name' => 'Score', 'field_type' => 'NUMBER']);

        $this->assertSame($expected, $result);
    }

    public function testCreateSuccessSelect(): void
    {
        $expected = ['id' => 4, 'name' => 'Mood', 'field_type' => 'SELECT', 'options' => '["Good","Bad"]', 'user_id' => 1];
        $this->repo->method('findByUserAndName')->willReturn(null);
        $this->repo->expects($this->once())
            ->method('create')
            ->with($this->callback(fn($data) => $data['options'] === json_encode(['Good', 'Bad'])))
            ->willReturn($expected);

        $result = $this->service->create(1, ['name' => 'Mood', 'field_type' => 'SELECT', 'options' => ['Good', 'Bad']]);

        $this->assertSame($expected, $result);
    }

    public function testCreateIgnoresOptionsForNonSelectType(): void
    {
        $expected = ['id' => 1, 'name' => 'Test', 'field_type' => 'TEXT', 'user_id' => 1];
        $this->repo->method('findByUserAndName')->willReturn(null);
        $this->repo->expects($this->once())
            ->method('create')
            ->with($this->callback(fn($data) => $data['options'] === null))
            ->willReturn($expected);

        $result = $this->service->create(1, ['name' => 'Test', 'field_type' => 'TEXT', 'options' => ['ignored']]);

        $this->assertSame($expected, $result);
    }

    // ── Show ─────────────────────────────────────────────────────

    public function testShowSuccess(): void
    {
        $field = ['id' => 1, 'user_id' => 1, 'name' => 'Confident', 'field_type' => 'BOOLEAN'];
        $this->repo->method('findById')->with(1)->willReturn($field);

        $result = $this->service->get(1, 1);

        $this->assertSame($field, $result);
    }

    public function testShowThrowsWhenNotFound(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->get(1, 999);
    }

    public function testShowThrowsForbiddenWhenNotOwner(): void
    {
        $field = ['id' => 1, 'user_id' => 2, 'name' => 'Confident', 'field_type' => 'BOOLEAN'];
        $this->repo->method('findById')->willReturn($field);

        $this->expectException(ForbiddenException::class);

        $this->service->get(1, 1);
    }

    // ── Update ───────────────────────────────────────────────────

    public function testUpdateSuccess(): void
    {
        $field = ['id' => 1, 'user_id' => 1, 'name' => 'Confident', 'field_type' => 'BOOLEAN'];
        $updated = ['id' => 1, 'user_id' => 1, 'name' => 'Very Confident', 'field_type' => 'BOOLEAN'];
        $this->repo->method('findById')->with(1)->willReturn($field);
        $this->repo->method('findByUserAndName')->willReturn(null);
        $this->repo->method('update')->willReturn($updated);

        $result = $this->service->update(1, 1, ['name' => 'Very Confident']);

        $this->assertSame($updated, $result);
    }

    public function testUpdateThrowsWhenNotFound(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->update(1, 999, ['name' => 'Test']);
    }

    public function testUpdateThrowsForbiddenWhenNotOwner(): void
    {
        $field = ['id' => 1, 'user_id' => 2, 'name' => 'Confident', 'field_type' => 'BOOLEAN'];
        $this->repo->method('findById')->willReturn($field);

        $this->expectException(ForbiddenException::class);

        $this->service->update(1, 1, ['name' => 'Test']);
    }

    public function testUpdateThrowsWhenDuplicateName(): void
    {
        $field = ['id' => 1, 'user_id' => 1, 'name' => 'Confident', 'field_type' => 'BOOLEAN'];
        $this->repo->method('findById')->willReturn($field);
        $this->repo->method('findByUserAndName')
            ->with(1, 'Score')
            ->willReturn(['id' => 2, 'name' => 'Score']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('custom_fields.error.duplicate_name');

        $this->service->update(1, 1, ['name' => 'Score']);
    }

    public function testUpdateAllowsSameNameOnSameField(): void
    {
        $field = ['id' => 1, 'user_id' => 1, 'name' => 'Confident', 'field_type' => 'BOOLEAN'];
        $this->repo->method('findById')->willReturn($field);
        $this->repo->method('findByUserAndName')
            ->with(1, 'Confident')
            ->willReturn(['id' => 1, 'name' => 'Confident']);
        $this->repo->method('update')->willReturn($field);

        $result = $this->service->update(1, 1, ['name' => 'Confident']);

        $this->assertSame($field, $result);
    }

    public function testUpdateSelectOptions(): void
    {
        $field = ['id' => 1, 'user_id' => 1, 'name' => 'Mood', 'field_type' => 'SELECT', 'options' => '["Good","Bad"]'];
        $updated = ['id' => 1, 'user_id' => 1, 'name' => 'Mood', 'field_type' => 'SELECT', 'options' => '["Good","Bad","Neutral"]'];
        $this->repo->method('findById')->willReturn($field);
        $this->repo->method('findByUserAndName')->willReturn(['id' => 1, 'name' => 'Mood']);
        $this->repo->expects($this->once())
            ->method('update')
            ->with(1, $this->callback(fn($data) => $data['options'] === json_encode(['Good', 'Bad', 'Neutral'])))
            ->willReturn($updated);

        $result = $this->service->update(1, 1, ['options' => ['Good', 'Bad', 'Neutral']]);

        $this->assertSame($updated, $result);
    }

    // ── Delete ───────────────────────────────────────────────────

    public function testDeleteSuccess(): void
    {
        $field = ['id' => 1, 'user_id' => 1, 'name' => 'Confident', 'field_type' => 'BOOLEAN'];
        $this->repo->method('findById')->with(1)->willReturn($field);
        $this->repo->expects($this->once())->method('softDelete')->with(1);

        $this->service->delete(1, 1);
    }

    public function testDeleteThrowsWhenNotFound(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->delete(1, 999);
    }

    public function testDeleteThrowsForbiddenWhenNotOwner(): void
    {
        $field = ['id' => 1, 'user_id' => 2, 'name' => 'Confident', 'field_type' => 'BOOLEAN'];
        $this->repo->method('findById')->willReturn($field);

        $this->expectException(ForbiddenException::class);

        $this->service->delete(1, 1);
    }

    public function testDeleteThrowsWhenIdIsZero(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('error.invalid_id');

        $this->service->delete(1, 0);
    }
}
