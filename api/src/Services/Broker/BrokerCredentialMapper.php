<?php

namespace App\Services\Broker;

use App\Enums\BrokerProvider;
use App\Enums\CtraderEnvironment;
use App\Exceptions\ValidationException;

/**
 * Single source of truth for the shape of each provider's credentials: which
 * request-body field feeds which credential key, which keys are secret, and
 * which are required.
 *
 * Backs three paths:
 *  - build()      → create a connection from scratch
 *  - merge()      → reconfigure an existing one; a blank/absent field keeps the
 *                   stored value, because secrets are never sent back to the
 *                   client and therefore come back empty from an untouched input
 *  - publicView() → what the API may safely return: non-secret identifiers in
 *                   clear (so the dialog prefills) and a set/unset flag per
 *                   secret (never the secret itself)
 */
class BrokerCredentialMapper
{
    /**
     * body field => [credential key, secret?, required?]
     *
     * Order matters: the first missing required field is the one reported back
     * as the offending `field`, so the dialog can focus the right input.
     */
    private const SPEC = [
        BrokerProvider::CTRADER->value => [
            'client_id'          => ['key' => 'client_id', 'secret' => false, 'required' => true],
            'client_secret'      => ['key' => 'client_secret', 'secret' => true, 'required' => true],
            'access_token'       => ['key' => 'access_token', 'secret' => true, 'required' => true],
            // Optional, but the only thing that lets a connection outlive its
            // access token: CtraderConnector::refreshCredentials() returns
            // early without it, so a connection created without one simply
            // stops working at expiry instead of renewing itself.
            'refresh_token'      => ['key' => 'refresh_token', 'secret' => true, 'required' => false],
            'account_id_ctrader' => ['key' => 'ctid_trader_account_id', 'secret' => false, 'required' => true, 'cast' => 'int', 'identity' => true],
            'environment'        => ['key' => 'environment', 'secret' => false, 'required' => false, 'enum' => CtraderEnvironment::class, 'default' => CtraderEnvironment::LIVE->value],
        ],
        BrokerProvider::METAAPI->value => [
            'api_token'          => ['key' => 'api_token', 'secret' => true, 'required' => true],
            'metaapi_account_id' => ['key' => 'metaapi_account_id', 'secret' => false, 'required' => true, 'identity' => true],
        ],
        BrokerProvider::OUINEX->value => [
            // No account number to key on: the API key IS the account as far as
            // Ouinex is concerned, so it is what identifies one.
            'service_api_key'    => ['key' => 'service_api_key', 'secret' => true, 'required' => true, 'identity' => true],
            'service_api_secret' => ['key' => 'service_api_secret', 'secret' => true, 'required' => true],
        ],
        BrokerProvider::BINGX->value => [
            'api_key'    => ['key' => 'api_key', 'secret' => true, 'required' => true, 'identity' => true],
            'api_secret' => ['key' => 'api_secret', 'secret' => true, 'required' => true],
        ],
    ];

    /**
     * Which credential identifies the broker-side account, and under which
     * request-body field the user supplies it.
     *
     * Two connections pointing at the same broker account each sync on their
     * own schedule, doubling the request volume against brokers that cap it —
     * and nothing in the schema can express the constraint, since the
     * identifier lives inside the encrypted credentials blob.
     *
     * @return array{field: string, key: string}|null null for an unknown provider
     */
    public function brokerAccountIdentity(string $provider): ?array
    {
        foreach (self::SPEC[$provider] ?? [] as $field => $spec) {
            if ($spec['identity'] ?? false) {
                return ['field' => $field, 'key' => $spec['key']];
            }
        }

        return null;
    }

    /**
     * Assemble a fresh credentials array from a request body.
     *
     * @throws ValidationException on an unknown provider or a missing required field
     */
    public function build(string $provider, array $body): array
    {
        return $this->assemble($provider, [], $body);
    }

    /**
     * Overlay a request body onto already-stored credentials. Blank or absent
     * fields keep their stored value; unknown body keys are dropped.
     *
     * @throws ValidationException on an unknown provider or a required field
     *                             that is neither stored nor supplied
     */
    public function merge(string $provider, array $existing, array $body): array
    {
        return $this->assemble($provider, $existing, $body);
    }

    /**
     * Non-secret identifiers (keyed by *body* field name so the client can feed
     * them straight back into the reconfigure form) plus a set/unset flag per
     * secret. Never returns a secret value.
     *
     * Unknown providers yield an empty view rather than throwing: a row with a
     * provider we no longer support must not break the connections listing.
     *
     * @return array{credentials_public: array<string,string>, credentials_set: array<string,bool>}
     */
    public function publicView(string $provider, array $credentials): array
    {
        $spec = self::SPEC[$provider] ?? null;
        if ($spec === null) {
            return ['credentials_public' => [], 'credentials_set' => []];
        }

        $public = [];
        $set = [];

        foreach ($spec as $field => $rule) {
            $value = $credentials[$rule['key']] ?? null;

            if ($rule['secret']) {
                $set[$field] = $value !== null && (string) $value !== '';
                continue;
            }

            if ($value !== null && (string) $value !== '') {
                $public[$field] = (string) $value;
            }
        }

        return ['credentials_public' => $public, 'credentials_set' => $set];
    }

    /**
     * True when the body carries at least one recognised, non-blank field for
     * this provider. Guards the reconfigure endpoint against an empty submit
     * silently resetting the connection status without changing anything.
     */
    public function hasAnyField(string $provider, array $body): bool
    {
        foreach (self::SPEC[$provider] ?? [] as $field => $rule) {
            if ($this->readField($body, $field) !== null) {
                return true;
            }
        }
        return false;
    }

    private function assemble(string $provider, array $existing, array $body): array
    {
        $spec = self::SPEC[$provider] ?? null;
        if ($spec === null) {
            throw new ValidationException('broker.error.unsupported_provider', 'provider');
        }

        $credentials = [];

        foreach ($spec as $field => $rule) {
            $key = $rule['key'];
            $supplied = $this->readField($body, $field);

            $value = $supplied ?? ($existing[$key] ?? null);
            if ($value === null || (string) $value === '') {
                $value = $rule['default'] ?? null;
            }

            if ($value === null || (string) $value === '') {
                if ($rule['required']) {
                    throw new ValidationException('broker.error.credentials_required', $field);
                }
                continue;
            }

            if (isset($rule['enum'])) {
                $enum = $rule['enum']::tryFrom(strtoupper((string) $value));
                if ($enum === null) {
                    throw new ValidationException('broker.error.invalid_environment', $field);
                }
                $value = $enum->value;
            }

            $credentials[$key] = ($rule['cast'] ?? null) === 'int' ? (int) $value : (string) $value;
        }

        return $credentials;
    }

    /**
     * Read one body field, trimmed. Returns null when absent or blank so the
     * caller can fall back to the stored value.
     *
     * trim() matters: password-manager autofill and copy-paste from a broker
     * portal drag trailing spaces or newlines, which silently invalidate every
     * signature/auth downstream and are invisible in the UI.
     */
    private function readField(array $body, string $field): ?string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }
        $value = trim((string) $body[$field]);
        return $value === '' ? null : $value;
    }
}
