<?php

namespace Tests\Unit\Services\Broker;

use App\Exceptions\ValidationException;
use App\Services\Broker\BrokerCredentialMapper;
use PHPUnit\Framework\TestCase;

/**
 * Per-provider credential assembly (docs/85-broker-connection-reconfigure.md).
 *
 * The mapper is the single place that knows which request body field feeds
 * which credential key, which ones are secret, and which are required. It
 * backs both the create path (build from scratch) and the reconfigure path
 * (merge over existing credentials, where a blank field means "keep the
 * stored value" — secrets are never echoed back to the client, so an
 * untouched password input arrives empty).
 */
class BrokerCredentialMapperTest extends TestCase
{
    private BrokerCredentialMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new BrokerCredentialMapper();
    }

    // ── build (create path) ─────────────────────────────────────────

    public function testBuildCtraderMapsBodyFieldsToCredentialKeys(): void
    {
        $credentials = $this->mapper->build('CTRADER', [
            'client_id' => '30528',
            'client_secret' => 'sEcReT',
            'access_token' => 'tok',
            'account_id_ctrader' => '12345678',
        ]);

        $this->assertSame('30528', $credentials['client_id']);
        $this->assertSame('sEcReT', $credentials['client_secret']);
        $this->assertSame('tok', $credentials['access_token']);
        // Body field is account_id_ctrader, credential key is the cTrader one.
        $this->assertSame(12345678, $credentials['ctid_trader_account_id']);
    }

    public function testBuildCtraderStoresAnOptionalRefreshToken(): void
    {
        // CtraderConnector::refreshCredentials() was fully implemented but
        // could never fire: it returns early without a refresh_token, and the
        // dialog never collected one. So an expired access token killed the
        // connection for good, with the fix already written but unreachable.
        $credentials = $this->mapper->build('CTRADER', [
            'client_id' => '30528',
            'client_secret' => 'sEcReT',
            'access_token' => 'tok',
            'refresh_token' => 'refresh',
            'account_id_ctrader' => '12345678',
        ]);

        $this->assertSame('refresh', $credentials['refresh_token']);
    }

    public function testBuildCtraderWorksWithoutARefreshToken(): void
    {
        // Optional: connections created before this existed, and tokens minted
        // without one, must keep working exactly as before.
        $credentials = $this->mapper->build('CTRADER', [
            'client_id' => '30528',
            'client_secret' => 'sEcReT',
            'access_token' => 'tok',
            'account_id_ctrader' => '12345678',
        ]);

        $this->assertArrayNotHasKey('refresh_token', $credentials);
    }

    public function testMergeKeepsAStoredRefreshTokenWhenTheInputIsBlank(): void
    {
        // Same rule as the other secrets: never sent back to the client, so an
        // untouched input arrives empty and must not wipe what is stored.
        $credentials = $this->mapper->merge(
            'CTRADER',
            [
                'client_id' => '30528',
                'client_secret' => 'stored',
                'access_token' => 'stored-tok',
                'refresh_token' => 'stored-refresh',
                'ctid_trader_account_id' => 12345678,
            ],
            ['refresh_token' => ''],
        );

        $this->assertSame('stored-refresh', $credentials['refresh_token']);
    }

    public function testPublicViewFlagsTheRefreshTokenWithoutRevealingIt(): void
    {
        $view = $this->mapper->publicView('CTRADER', [
            'client_id' => '30528',
            'client_secret' => 'sEcReT',
            'access_token' => 'tok',
            'refresh_token' => 'refresh',
            'ctid_trader_account_id' => 12345678,
        ]);

        $this->assertTrue($view['credentials_set']['refresh_token']);
        $this->assertArrayNotHasKey('refresh_token', $view['credentials_public']);
    }

    public function testBuildCtraderDefaultsToLiveEnvironment(): void
    {
        $credentials = $this->mapper->build('CTRADER', [
            'client_id' => 'a',
            'client_secret' => 'b',
            'access_token' => 'c',
            'account_id_ctrader' => '1',
        ]);

        $this->assertSame('LIVE', $credentials['environment']);
    }

    public function testBuildCtraderAcceptsDemoEnvironment(): void
    {
        $credentials = $this->mapper->build('CTRADER', [
            'client_id' => 'a',
            'client_secret' => 'b',
            'access_token' => 'c',
            'account_id_ctrader' => '1',
            'environment' => 'DEMO',
        ]);

        $this->assertSame('DEMO', $credentials['environment']);
    }

    public function testBuildRejectsUnknownEnvironment(): void
    {
        $this->expectException(ValidationException::class);

        $this->mapper->build('CTRADER', [
            'client_id' => 'a',
            'client_secret' => 'b',
            'access_token' => 'c',
            'account_id_ctrader' => '1',
            'environment' => 'STAGING',
        ]);
    }

    public function testBuildTrimsEveryCredential(): void
    {
        // Password-manager autofill and copy-paste from the broker portal drag
        // trailing whitespace, which silently invalidates auth downstream.
        $credentials = $this->mapper->build('BINGX', [
            'api_key' => "  key  \n",
            'api_secret' => "\tsecret ",
        ]);

        $this->assertSame('key', $credentials['api_key']);
        $this->assertSame('secret', $credentials['api_secret']);
    }

    public function testBuildThrowsWhenRequiredFieldMissing(): void
    {
        try {
            $this->mapper->build('CTRADER', [
                'client_id' => 'a',
                'client_secret' => '',
                'access_token' => 'c',
                'account_id_ctrader' => '1',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('broker.error.credentials_required', $e->getMessageKey());
            // The field name points the client at the offending input.
            $this->assertSame('client_secret', $e->getField());
        }
    }

    public function testBuildRejectsUnsupportedProvider(): void
    {
        $this->expectException(ValidationException::class);
        $this->mapper->build('KRAKEN', ['api_key' => 'a']);
    }

    public function testBuildMetaApiOuinexAndBingx(): void
    {
        $meta = $this->mapper->build('METAAPI', [
            'api_token' => 'tok',
            'metaapi_account_id' => 'acc',
        ]);
        $this->assertSame(['api_token' => 'tok', 'metaapi_account_id' => 'acc'], $meta);

        $ouinex = $this->mapper->build('OUINEX', [
            'service_api_key' => 'k',
            'service_api_secret' => 's',
        ]);
        $this->assertSame(['service_api_key' => 'k', 'service_api_secret' => 's'], $ouinex);

        $bingx = $this->mapper->build('BINGX', [
            'api_key' => 'k',
            'api_secret' => 's',
        ]);
        $this->assertSame(['api_key' => 'k', 'api_secret' => 's'], $bingx);
    }

    // ── merge (reconfigure path) ────────────────────────────────────

    public function testMergeKeepsStoredValueWhenFieldIsBlank(): void
    {
        $existing = [
            'client_id' => '30528',
            'client_secret' => 'old-secret',
            'access_token' => 'old-token',
            'ctid_trader_account_id' => 12345678,
            'environment' => 'LIVE',
        ];

        // The user only retyped the client secret — every other input was left
        // untouched, so the dialog submits them empty.
        $merged = $this->mapper->merge('CTRADER', $existing, [
            'client_secret' => 'new-secret',
            'access_token' => '',
        ]);

        $this->assertSame('new-secret', $merged['client_secret']);
        $this->assertSame('old-token', $merged['access_token']);
        $this->assertSame('30528', $merged['client_id']);
        $this->assertSame(12345678, $merged['ctid_trader_account_id']);
    }

    public function testMergeKeepsStoredValueWhenFieldIsAbsent(): void
    {
        $existing = ['api_key' => 'k', 'api_secret' => 's'];

        $merged = $this->mapper->merge('BINGX', $existing, ['api_key' => 'k2']);

        $this->assertSame('k2', $merged['api_key']);
        $this->assertSame('s', $merged['api_secret']);
    }

    public function testMergeCanSwitchCtraderEnvironment(): void
    {
        $existing = [
            'client_id' => 'a',
            'client_secret' => 'b',
            'access_token' => 'c',
            'ctid_trader_account_id' => 1,
            'environment' => 'LIVE',
        ];

        $merged = $this->mapper->merge('CTRADER', $existing, ['environment' => 'DEMO']);

        $this->assertSame('DEMO', $merged['environment']);
        // Secrets untouched.
        $this->assertSame('b', $merged['client_secret']);
    }

    public function testMergeBackfillsEnvironmentOnConnectionsCreatedBeforeTheField(): void
    {
        // Connections stored before the live/demo selector shipped have no
        // environment key at all — they were implicitly using the global host.
        $existing = [
            'client_id' => 'a',
            'client_secret' => 'b',
            'access_token' => 'c',
            'ctid_trader_account_id' => 1,
        ];

        $merged = $this->mapper->merge('CTRADER', $existing, ['client_secret' => 'b2']);

        $this->assertSame('LIVE', $merged['environment']);
    }

    public function testMergeThrowsWhenAStoredRequiredFieldIsMissingAndNotSupplied(): void
    {
        $this->expectException(ValidationException::class);

        $this->mapper->merge('CTRADER', ['client_id' => 'a'], ['client_secret' => 'b']);
    }

    public function testMergeDropsUnknownBodyKeys(): void
    {
        $merged = $this->mapper->merge('BINGX', ['api_key' => 'k', 'api_secret' => 's'], [
            'api_key' => 'k2',
            'provider' => 'CTRADER',
            'account_id' => 99,
        ]);

        $this->assertSame(['api_key' => 'k2', 'api_secret' => 's'], $merged);
    }

    // ── publicView (what the client may see) ────────────────────────

    public function testPublicViewExposesIdentifiersAndFlagsSecretsWithoutRevealingThem(): void
    {
        $view = $this->mapper->publicView('CTRADER', [
            'client_id' => '30528',
            'client_secret' => 'sEcReT',
            // Distinctive values: a short one like "tok" would match the
            // "access_token" key name and make the leak assertion below lie.
            'access_token' => 'xyzzy-access-value',
            'ctid_trader_account_id' => 12345678,
            'environment' => 'DEMO',
        ]);

        // Non-secret identifiers come back so the reconfigure dialog prefills.
        $this->assertSame([
            'client_id' => '30528',
            'account_id_ctrader' => '12345678',
            'environment' => 'DEMO',
        ], $view['credentials_public']);

        // Secrets are reported as set/unset only — never echoed. refresh_token
        // is optional, so an older connection reports it as simply unset.
        $this->assertSame([
            'client_secret' => true,
            'access_token' => true,
            'refresh_token' => false,
        ], $view['credentials_set']);

        $flat = json_encode($view);
        $this->assertStringNotContainsString('sEcReT', $flat);
        $this->assertStringNotContainsString('xyzzy-access-value', $flat);
    }

    public function testPublicViewMarksMissingSecretAsUnset(): void
    {
        $view = $this->mapper->publicView('BINGX', ['api_key' => 'k', 'api_secret' => '']);

        $this->assertSame(['api_key' => true, 'api_secret' => false], $view['credentials_set']);
        $this->assertSame([], $view['credentials_public']);
    }

    public function testPublicViewOfUnknownProviderIsEmptyRatherThanFatal(): void
    {
        // Defensive: a row with a provider we no longer support must not break
        // the connections listing.
        $view = $this->mapper->publicView('KRAKEN', ['api_key' => 'k']);

        $this->assertSame(['credentials_public' => [], 'credentials_set' => []], $view);
    }

    // ── shared / own split (docs/91-broker-shared-credentials.md) ───

    public function testSharedFieldsOfCtraderAreTheFourAppCredentials(): void
    {
        // What a second cTrader connection must not make the user retype. The
        // account id is deliberately absent: it is the one thing that differs
        // between two connections of the same app.
        $this->assertSame(
            ['client_id', 'client_secret', 'access_token', 'refresh_token'],
            $this->mapper->sharedFields('CTRADER'),
        );
    }

    public function testSharedFieldsOfMetaApiIsTheApiToken(): void
    {
        $this->assertSame(['api_token'], $this->mapper->sharedFields('METAAPI'));
    }

    public function testOuinexAndBingxShareNothing(): void
    {
        // Their API key IS the account, so there is nothing above the
        // connection to hoist. This is the test of the abstraction: they must
        // traverse the feature without a single behaviour change.
        $this->assertSame([], $this->mapper->sharedFields('OUINEX'));
        $this->assertSame([], $this->mapper->sharedFields('BINGX'));
    }

    public function testSharedFieldsOfUnknownProviderIsEmpty(): void
    {
        $this->assertSame([], $this->mapper->sharedFields('KRAKEN'));
    }

    public function testCtraderEnvironmentStaysWithTheConnection(): void
    {
        // Not shared on purpose: which cTrader host an account lives on is a
        // property of the account, not of the app credentials. One access token
        // can list both live and demo accounts.
        $this->assertNotContains('environment', $this->mapper->sharedFields('CTRADER'));
    }

    public function testSplitCtraderSeparatesAppCredentialsFromTheAccount(): void
    {
        $split = $this->mapper->split('CTRADER', [
            'client_id' => '30528',
            'client_secret' => 'sEcReT',
            'access_token' => 'tok',
            'refresh_token' => 'refresh',
            'ctid_trader_account_id' => 12345678,
            'environment' => 'DEMO',
        ]);

        $this->assertSame([
            'client_id' => '30528',
            'client_secret' => 'sEcReT',
            'access_token' => 'tok',
            'refresh_token' => 'refresh',
        ], $split['shared']);

        $this->assertSame([
            'ctid_trader_account_id' => 12345678,
            'environment' => 'DEMO',
        ], $split['own']);
    }

    public function testSplitOmitsAnAbsentOptionalSharedCredential(): void
    {
        // A connection created without a refresh token must not write an empty
        // key into the shared row — publicView would then report it as set.
        $split = $this->mapper->split('CTRADER', [
            'client_id' => '30528',
            'client_secret' => 'sEcReT',
            'access_token' => 'tok',
            'ctid_trader_account_id' => 12345678,
        ]);

        $this->assertArrayNotHasKey('refresh_token', $split['shared']);
    }

    public function testSplitLeavesEverythingOnTheConnectionForBingx(): void
    {
        $split = $this->mapper->split('BINGX', ['api_key' => 'k', 'api_secret' => 's']);

        $this->assertSame([], $split['shared']);
        $this->assertSame(['api_key' => 'k', 'api_secret' => 's'], $split['own']);
    }

    public function testSplitOfUnknownProviderKeepsEverythingOnTheConnection(): void
    {
        // Same defensive stance as publicView(): a provider we no longer
        // support keeps behaving exactly as it did before sharing existed.
        $split = $this->mapper->split('KRAKEN', ['api_key' => 'k']);

        $this->assertSame([], $split['shared']);
        $this->assertSame(['api_key' => 'k'], $split['own']);
    }
}
