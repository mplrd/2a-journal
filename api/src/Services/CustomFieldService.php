<?php

namespace App\Services;

use App\Enums\CustomFieldType;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\CustomFieldDefinitionRepository;
use App\Repositories\CustomFieldValueRepository;

class CustomFieldService
{
    private CustomFieldDefinitionRepository $repo;
    private ?CustomFieldValueRepository $valueRepo;

    public function __construct(CustomFieldDefinitionRepository $repo, ?CustomFieldValueRepository $valueRepo = null)
    {
        $this->repo = $repo;
        $this->valueRepo = $valueRepo;
    }

    public function list(int $userId): array
    {
        return $this->repo->findAllByUserId($userId);
    }

    public function create(int $userId, array $data): array
    {
        $name = trim($data['name'] ?? '');
        $fieldType = $data['field_type'] ?? '';

        if ($name === '') {
            throw new ValidationException('custom_fields.error.field_required', 'name');
        }

        if (mb_strlen($name) > 100) {
            throw new ValidationException('custom_fields.error.name_too_long', 'name');
        }

        if ($fieldType === '') {
            throw new ValidationException('custom_fields.error.field_required', 'field_type');
        }

        $type = CustomFieldType::tryFrom($fieldType);
        if (!$type) {
            throw new ValidationException('custom_fields.error.invalid_field_type', 'field_type');
        }

        $existing = $this->repo->findByUserAndName($userId, $name);
        if ($existing) {
            throw new ValidationException('custom_fields.error.duplicate_name', 'name');
        }

        $options = null;
        if ($type === CustomFieldType::SELECT) {
            $options = $data['options'] ?? null;
            if (!is_array($options) || empty($options)) {
                throw new ValidationException('custom_fields.error.select_options_required', 'options');
            }
            $options = json_encode(array_values($options));
        }

        $sortOrder = $this->repo->getNextSortOrder($userId);

        return $this->repo->create([
            'user_id' => $userId,
            'name' => $name,
            'field_type' => $fieldType,
            'options' => $options,
            'sort_order' => $sortOrder,
        ]);
    }

    public function get(int $userId, int $id): array
    {
        $field = $this->repo->findById($id);

        if (!$field) {
            throw new NotFoundException('custom_fields.error.not_found');
        }

        if ((int) $field['user_id'] !== $userId) {
            throw new ForbiddenException('custom_fields.error.forbidden');
        }

        return $field;
    }

    public function update(int $userId, int $id, array $data): array
    {
        $field = $this->repo->findById($id);

        if (!$field) {
            throw new NotFoundException('custom_fields.error.not_found');
        }

        if ((int) $field['user_id'] !== $userId) {
            throw new ForbiddenException('custom_fields.error.forbidden');
        }

        $updateData = [];

        if (isset($data['name'])) {
            $name = trim($data['name']);

            if ($name === '') {
                throw new ValidationException('custom_fields.error.field_required', 'name');
            }

            if (mb_strlen($name) > 100) {
                throw new ValidationException('custom_fields.error.name_too_long', 'name');
            }

            $existing = $this->repo->findByUserAndName($userId, $name);
            if ($existing && (int) $existing['id'] !== $id) {
                throw new ValidationException('custom_fields.error.duplicate_name', 'name');
            }

            $updateData['name'] = $name;
        }

        if (isset($data['options']) && $field['field_type'] === 'SELECT') {
            if (!is_array($data['options']) || empty($data['options'])) {
                throw new ValidationException('custom_fields.error.select_options_required', 'options');
            }
            $updateData['options'] = json_encode(array_values($data['options']));
        }

        if (array_key_exists('sort_order', $data)) {
            $updateData['sort_order'] = (int) $data['sort_order'];
        }

        if (array_key_exists('is_active', $data)) {
            $updateData['is_active'] = $data['is_active'] ? 1 : 0;
        }

        return $this->repo->update($id, $updateData);
    }

    public function delete(int $userId, int $id): void
    {
        if ($id <= 0) {
            throw new ValidationException('error.invalid_id', 'id');
        }

        $field = $this->repo->findById($id);

        if (!$field) {
            throw new NotFoundException('custom_fields.error.not_found');
        }

        if ((int) $field['user_id'] !== $userId) {
            throw new ForbiddenException('custom_fields.error.forbidden');
        }

        $this->repo->softDelete($id);
    }

    public function validateAndSaveValues(int $userId, int $tradeId, array $customFields): void
    {
        if (empty($customFields)) {
            return;
        }

        foreach ($customFields as $entry) {
            $fieldId = (int) ($entry['field_id'] ?? 0);
            $value = $entry['value'] ?? null;

            $def = $this->repo->findById($fieldId);

            if (!$def || (int) $def['user_id'] !== $userId) {
                throw new ValidationException('custom_fields.error.field_not_found', 'custom_fields');
            }

            if (!(int) $def['is_active']) {
                throw new ValidationException('custom_fields.error.field_inactive', 'custom_fields');
            }

            if ($value !== null) {
                $this->validateValueByType($def, $value);
            }
        }

        $this->valueRepo->saveForTrade($tradeId, $customFields);
    }

    public function findByTradeId(int $tradeId): array
    {
        return $this->valueRepo->findByTradeId($tradeId);
    }

    public function findByTradeIds(array $tradeIds): array
    {
        return $this->valueRepo->findByTradeIds($tradeIds);
    }

    private function validateValueByType(array $def, string $value): void
    {
        switch ($def['field_type']) {
            case 'BOOLEAN':
                if (!in_array($value, ['true', 'false'], true)) {
                    throw new ValidationException('custom_fields.error.invalid_boolean', 'custom_fields');
                }
                break;

            case 'TEXT':
                if (mb_strlen($value) > 5000) {
                    throw new ValidationException('custom_fields.error.text_too_long', 'custom_fields');
                }
                break;

            case 'NUMBER':
                if (!is_numeric($value)) {
                    throw new ValidationException('custom_fields.error.invalid_number', 'custom_fields');
                }
                break;

            case 'SELECT':
                $options = json_decode($def['options'] ?? '[]', true) ?: [];
                if (!in_array($value, $options, true)) {
                    throw new ValidationException('custom_fields.error.invalid_option', 'custom_fields');
                }
                break;
        }
    }
}
