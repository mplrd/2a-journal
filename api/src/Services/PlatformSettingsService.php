<?php

namespace App\Services;

use App\Enums\SettingType;
use App\Exceptions\ValidationException;
use App\Repositories\PlatformSettingsRepository;

/**
 * Resolves platform settings with the priority chain:
 *   1. Database (admin-editable, runtime override)
 *   2. Environment variable (operator-set, deploy-time)
 *   3. null
 *
 * No hardcoded default in the resolver. Each caller decides how to handle
 * a null result (treat as off, log+skip, throw, etc.). This makes mis-
 * configuration immediately visible instead of being masked by a default.
 *
 * Only the keys declared in KNOWN_SETTINGS are valid; resolve()/update()
 * silently or loudly reject anything else.
 */
class PlatformSettingsService
{
    /**
     * Whitelist of settings exposed via the admin BO. Each entry maps to:
     *   - type: BOOL | INT | STRING (drives validation + coercion)
     *   - env_var: legacy env variable name used as fallback
     *   - description: shown in the BO UI for context
     */
    public static function knownSettings(): array
    {
        return [
            'broker_auto_sync_enabled' => [
                'type' => SettingType::BOOL->value,
                'env_var' => 'BROKER_AUTO_SYNC_ENABLED',
                'description' => 'admin.settings.desc.broker_auto_sync_enabled',
            ],
            'broker_sync_interval_minutes' => [
                'type' => SettingType::INT->value,
                'env_var' => 'BROKER_SYNC_INTERVAL_MINUTES',
                'description' => 'admin.settings.desc.broker_sync_interval_minutes',
            ],
            'broker_sync_max_failures' => [
                'type' => SettingType::INT->value,
                'env_var' => 'BROKER_SYNC_MAX_FAILURES',
                'description' => 'admin.settings.desc.broker_sync_max_failures',
            ],
            'email_verification_enabled' => [
                'type' => SettingType::BOOL->value,
                'env_var' => 'EMAIL_VERIFICATION_ENABLED',
                'description' => 'admin.settings.desc.email_verification_enabled',
            ],
            'mail_enabled' => [
                'type' => SettingType::BOOL->value,
                'env_var' => 'MAIL_ENABLED',
                'description' => 'admin.settings.desc.mail_enabled',
            ],
            'mail_from_address' => [
                'type' => SettingType::STRING->value,
                'env_var' => 'MAIL_FROM_ADDRESS',
                'description' => 'admin.settings.desc.mail_from_address',
            ],
            'billing_grace_days' => [
                'type' => SettingType::INT->value,
                'env_var' => 'BILLING_GRACE_DAYS',
                'description' => 'admin.settings.desc.billing_grace_days',
            ],
        ];
    }

    public function __construct(private PlatformSettingsRepository $repo) {}

    /**
     * Resolve a setting's effective value.
     *
     * @return mixed|null  Typed value (bool/int/string) when defined, null
     *                     when neither DB nor env carries a value, and null
     *                     also for unknown keys.
     */
    public function resolve(string $key): mixed
    {
        $known = self::knownSettings();
        if (!isset($known[$key])) {
            return null;
        }
        $meta = $known[$key];

        // 1. Database
        $row = $this->repo->get($key);
        if ($row !== null && $row['setting_value'] !== null && $row['setting_value'] !== '') {
            return $this->cast($row['setting_value'], $meta['type']);
        }

        // 2. Environment variable
        $envValue = getenv($meta['env_var']);
        if ($envValue !== false && $envValue !== '') {
            return $this->cast($envValue, $meta['type']);
        }

        // 3. null — caller handles
        return null;
    }

    /**
     * List the effective state of every known setting, with the source it
     * came from so the BO can surface "this is from env, redeploy needed
     * to drop it" vs "this is overridden in DB".
     *
     * @return array<int, array{key: string, type: string, value: mixed, source: 'db'|'env'|'default', description: string, env_var: string, updated_at?: string, updated_by_user_id?: int}>
     */
    public function list(): array
    {
        $dbRows = $this->repo->list();
        $byKey = [];
        foreach ($dbRows as $row) {
            $byKey[$row['setting_key']] = $row;
        }

        $out = [];
        $known = self::knownSettings();
        foreach ($known as $key => $meta) {
            $value = null;
            $source = 'default';
            $updatedAt = null;
            $updatedByUserId = null;

            if (isset($byKey[$key]) && $byKey[$key]['setting_value'] !== null && $byKey[$key]['setting_value'] !== '') {
                $value = $this->cast($byKey[$key]['setting_value'], $meta['type']);
                $source = 'db';
                $updatedAt = $byKey[$key]['updated_at'] ?? null;
                $updatedByUserId = isset($byKey[$key]['updated_by_user_id']) ? (int) $byKey[$key]['updated_by_user_id'] : null;
            } else {
                $envValue = getenv($meta['env_var']);
                if ($envValue !== false && $envValue !== '') {
                    $value = $this->cast($envValue, $meta['type']);
                    $source = 'env';
                }
            }

            $out[] = [
                'key' => $key,
                'type' => $meta['type'],
                'value' => $value,
                'source' => $source,
                'description' => $meta['description'],
                'env_var' => $meta['env_var'],
                'updated_at' => $updatedAt,
                'updated_by_user_id' => $updatedByUserId,
            ];
        }
        return $out;
    }

    /**
     * Update a setting. Validates the key is known and the value matches
     * the declared type. Persists the raw string in DB; coercion happens
     * on read.
     */
    public function update(string $key, mixed $value, int $adminUserId): void
    {
        $known = self::knownSettings();
        if (!isset($known[$key])) {
            throw new ValidationException('admin.settings.error.unknown_key', 'key');
        }
        $meta = $known[$key];

        $stringValue = $this->validateAndStringify($value, $meta['type']);
        $this->repo->upsert($key, $stringValue, $meta['type'], $meta['description'], $adminUserId);
    }

    private function cast(string $raw, string $type): mixed
    {
        return match (SettingType::tryFrom($type)) {
            SettingType::BOOL => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            SettingType::INT => (int) $raw,
            SettingType::STRING => $raw,
            default => $raw,
        };
    }

    private function validateAndStringify(mixed $value, string $type): string
    {
        return match (SettingType::tryFrom($type)) {
            SettingType::BOOL => $this->stringifyBool($value),
            SettingType::INT => $this->stringifyInt($value),
            SettingType::STRING => $this->stringifyString($value),
            default => throw new ValidationException('admin.settings.error.invalid_type', 'value'),
        };
    }

    private function stringifyBool(mixed $value): string
    {
        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            throw new ValidationException('admin.settings.error.invalid_type', 'value');
        }
        return $bool ? 'true' : 'false';
    }

    private function stringifyInt(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return $value;
        }
        throw new ValidationException('admin.settings.error.invalid_type', 'value');
    }

    private function stringifyString(mixed $value): string
    {
        if (!is_string($value)) {
            throw new ValidationException('admin.settings.error.invalid_type', 'value');
        }
        return $value;
    }
}
