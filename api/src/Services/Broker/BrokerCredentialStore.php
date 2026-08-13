<?php

namespace App\Services\Broker;

use App\Repositories\BrokerCredentialRepository;

/**
 * The single place that knows credentials live in two rows
 * (docs/91-broker-shared-credentials.md).
 *
 * A provider declares, in BrokerCredentialMapper::SPEC, which credentials
 * belong to the user's broker *application* rather than to one account. Those
 * are stored once per (user, provider) in broker_credentials; the rest stays on
 * the connection row. Everything downstream — connectors, sync, webhook order
 * placement — keeps receiving one flat credentials array, so ConnectorInterface
 * never learned about any of this.
 *
 * The reads are deliberately tolerant. A row encrypted under a rotated
 * BROKER_ENCRYPTION_KEY must stay listable — and therefore reconfigurable or
 * deletable — instead of fataling the accounts screen; an empty array means
 * "nothing to prefill, retype it", which the mapper's merge() then enforces.
 */
class BrokerCredentialStore
{
    public function __construct(
        private BrokerCredentialRepository $credentialRepo,
        private CredentialEncryptionService $crypto,
        private BrokerCredentialMapper $mapper,
    ) {}

    /**
     * The full credentials a connector needs: the connection's own row with the
     * user's shared credentials laid underneath.
     *
     * The connection wins on a key present in both. That only happens on a row
     * written before this feature or by a provider that shares nothing, and in
     * both cases the connection's copy is the authoritative one.
     */
    public function forConnection(array $connection): array
    {
        $own = $this->ownOf($connection);
        $shared = $this->sharedFor((int) $connection['user_id'], $connection['provider']);

        return $own + $shared;
    }

    /** Only what the connection row itself holds. */
    public function ownOf(array $connection): array
    {
        return $this->decryptOrEmpty(
            $connection['credentials_encrypted'] ?? null,
            $connection['credentials_iv'] ?? null,
        );
    }

    /** The user's app credentials for a provider, or [] when none are stored. */
    public function sharedFor(int $userId, string $provider): array
    {
        $row = $this->credentialRepo->findByUserAndProvider($userId, $provider);
        if ($row === null) {
            return [];
        }

        return $this->decryptOrEmpty($row['credentials_encrypted'], $row['credentials_iv']);
    }

    /**
     * Every provider this user has app credentials stored for, decrypted.
     *
     * @return array<string, array> provider => credentials
     */
    public function allSharedFor(int $userId): array
    {
        $shared = [];

        foreach ($this->credentialRepo->findAllByUserId($userId) as $row) {
            $credentials = $this->decryptOrEmpty($row['credentials_encrypted'], $row['credentials_iv']);
            if ($credentials !== []) {
                $shared[$row['provider']] = $credentials;
            }
        }

        return $shared;
    }

    /**
     * True when a token *renewal* succeeded for the user's provider within the
     * last $seconds — so a token another connection just renewed must not be
     * renewed again.
     *
     * Renewed, not written: typing or reconfiguring credentials leaves the
     * window shut, because the access token being pasted can be arbitrarily
     * old. Keying this on the last write is the regression migration 037 undoes
     * — it silenced the refresh on the first sync after connecting, the one
     * sync that needs it most.
     *
     * False when nothing is shared, which is what keeps Ouinex and BingX (and
     * any first connection) on exactly their old refresh behaviour.
     */
    public function sharedRenewedWithin(int $userId, string $provider, int $seconds): bool
    {
        $age = $this->credentialRepo->secondsSinceRefresh($userId, $provider);

        return $age !== null && $age <= $seconds;
    }

    /**
     * Reserve the right to renew this user's token for a provider.
     *
     * True when there is nothing shared: a provider that keeps no shared row —
     * Ouinex, BingX, or a user's very first connection — has no token for two
     * syncs to fight over, so reserving must never become a reason not to
     * refresh. That would turn a race fix into a silent expiry.
     *
     * Otherwise the reservation is a conditional UPDATE and exactly one caller
     * wins it. See {@see \App\Repositories\BrokerCredentialRepository::claimRefresh()}.
     */
    public function claimSharedRefresh(int $userId, string $provider, int $staleAfterSeconds): bool
    {
        if ($this->credentialRepo->findByUserAndProvider($userId, $provider) === null) {
            return true;
        }

        return $this->credentialRepo->claimRefresh($userId, $provider, $staleAfterSeconds);
    }

    /**
     * Release the reservation. A no-op UPDATE when the user has no shared row,
     * which is cheaper than threading "did I really take a claim?" back through
     * the caller.
     */
    public function releaseSharedRefresh(int $userId, string $provider): void
    {
        $this->credentialRepo->releaseRefresh($userId, $provider);
    }

    /**
     * Persist a full credentials array: the shared part goes to the user's row,
     * and the connection-scoped part comes back encrypted for the caller to
     * write onto the connection.
     *
     * The caller owns the connection write because this same method serves a
     * row that does not exist yet (create) and one that does (reconfigure,
     * refreshed access token).
     *
     * `$fromRefresh` tells those apart, and only the sync's successful token
     * renewal may set it: it is what opens the skip window read by
     * sharedRenewedWithin(). A create or a reconfigure must leave it false.
     *
     * @return array{ciphertext: string, iv: string}
     */
    public function store(int $userId, string $provider, array $credentials, bool $fromRefresh = false): array
    {
        $split = $this->mapper->split($provider, $credentials);

        if ($split['shared'] !== []) {
            $encrypted = $this->crypto->encrypt($split['shared']);
            $this->credentialRepo->upsert($userId, $provider, $encrypted['ciphertext'], $encrypted['iv'], $fromRefresh);
        }

        return $this->crypto->encrypt($split['own']);
    }

    /**
     * Forget the user's app credentials for a provider. Called once their last
     * connection to it is gone — see BrokerConnectionService::deleteConnection().
     */
    public function forget(int $userId, string $provider): void
    {
        $this->credentialRepo->deleteForUserAndProvider($userId, $provider);
    }

    private function decryptOrEmpty(?string $ciphertext, ?string $iv): array
    {
        if ($ciphertext === null || $iv === null) {
            return [];
        }

        try {
            return $this->crypto->decrypt($ciphertext, $iv);
        } catch (\Throwable) {
            return [];
        }
    }
}
