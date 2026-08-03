<?php

namespace Tests\Unit;

use App\Services\PlatformSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract test: the API answers with i18n keys, never with user-facing text
 * (cf. CLAUDE.md "API returns i18n translation keys, not text").
 *
 * A key the backend can emit but that no locale file defines would surface as
 * a raw dotted string in the UI. This test walks the backend sources, collects
 * every key that can reach a client, and asserts it is translated.
 *
 * Two SPAs consume the API and each ships its own locales:
 *   - frontend/ — the user app (most namespaces)
 *   - admin/    — the back-office SPA (the `admin.*` namespace, plus its own
 *                 copy of the `auth.*` keys it can hit while logging in)
 *
 * A key therefore only has to be defined by one of them, but the SPA that
 * defines it must do so in BOTH languages — a key present in fr.json only
 * would fall back to the raw key for English users.
 */
class I18nMessageKeysTest extends TestCase
{
    private const EXCEPTIONS = [
        'ValidationException',
        'NotFoundException',
        'UnauthorizedException',
        'ForbiddenException',
        'TooManyRequestsException',
        'HttpException',
        'BrokerOrderException',
        'BrokerRateLimitException',
    ];

    /** SPA name => [locale => list of dot-notation keys] */
    private static array $locales = [];

    public static function setUpBeforeClass(): void
    {
        foreach (['frontend', 'admin'] as $spa) {
            foreach (['fr', 'en'] as $locale) {
                self::$locales[$spa][$locale] = self::flatten(self::loadLocale($spa, $locale));
            }
        }
    }

    /**
     * @return list<array{string}>
     */
    public static function spaProvider(): array
    {
        return [['frontend'], ['admin']];
    }

    #[DataProvider('spaProvider')]
    public function testLocaleFilesShareTheExactSameKeySet(string $spa): void
    {
        $fr = self::$locales[$spa]['fr'];
        $en = self::$locales[$spa]['en'];

        $this->assertSame(
            [],
            array_values(array_diff($en, $fr)),
            "{$spa}: keys present in en.json but missing from fr.json"
        );
        $this->assertSame(
            [],
            array_values(array_diff($fr, $en)),
            "{$spa}: keys present in fr.json but missing from en.json"
        );
    }

    public function testEveryExceptionMessageKeyIsTranslated(): void
    {
        $keys = $this->collectExceptionMessageKeys();

        $this->assertNotEmpty($keys, 'Sanity check: no exception message key was collected');
        $this->assertAllTranslated($keys);
    }

    public function testEveryPlatformSettingDescriptionIsTranslated(): void
    {
        $descriptions = [];
        foreach (PlatformSettingsService::knownSettings() as $key => $meta) {
            $descriptions[$meta['description']] = "PlatformSettingsService::knownSettings()['{$key}']";
        }

        $this->assertNotEmpty($descriptions, 'Sanity check: no setting description was collected');
        $this->assertAllTranslated($descriptions);
    }

    /**
     * Every key must be fully translated (fr + en) by at least one SPA.
     *
     * @param array<string, string> $keys key => human-readable origin
     */
    private function assertAllTranslated(array $keys): void
    {
        $problems = [];

        foreach ($keys as $key => $origin) {
            $definedBy = [];
            $partial = [];

            foreach (self::$locales as $spa => $byLocale) {
                $inFr = in_array($key, $byLocale['fr'], true);
                $inEn = in_array($key, $byLocale['en'], true);
                if ($inFr && $inEn) {
                    $definedBy[] = $spa;
                } elseif ($inFr || $inEn) {
                    $partial[] = $spa . ' (' . ($inFr ? 'fr only' : 'en only') . ')';
                }
            }

            if ($definedBy !== []) {
                continue;
            }

            $detail = $partial === []
                ? 'defined by no SPA'
                : 'only partially defined by ' . implode(', ', $partial);
            $problems[] = "  {$key} — {$detail} (emitted by {$origin})";
        }

        $this->assertSame(
            [],
            $problems,
            "Backend emits i18n keys that no SPA fully translates:\n" . implode("\n", $problems) . "\n"
        );
    }

    /**
     * Scans src/ for `throw new SomeException('key.path', ...)` and
     * `'message_key' => 'key.path'`.
     *
     * @return array<string, string> key => "relative/path.php:line"
     */
    private function collectExceptionMessageKeys(): array
    {
        $exceptions = implode('|', self::EXCEPTIONS);
        $throwPattern = '/new\s+(?:' . $exceptions . ')\s*\(\s*[\'"]([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)[\'"]/';
        $messageKeyPattern = '/[\'"]message_key[\'"]\s*=>\s*[\'"]([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)[\'"]/';

        $srcDir = dirname(__DIR__, 2) . '/src';
        $found = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(dirname($srcDir)) + 1));
            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);

            foreach ($lines as $index => $line) {
                foreach ([$throwPattern, $messageKeyPattern] as $pattern) {
                    if (preg_match($pattern, $line, $matches)) {
                        $found[$matches[1]] ??= $relative . ':' . ($index + 1);
                    }
                }
            }
        }

        return $found;
    }

    private static function loadLocale(string $spa, string $locale): array
    {
        $path = dirname(__DIR__, 3) . "/{$spa}/src/locales/{$locale}.json";

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string> dot-notation keys
     */
    private static function flatten(array $messages, string $prefix = ''): array
    {
        $keys = [];
        foreach ($messages as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $keys = array_merge($keys, self::flatten($value, $path));
            } else {
                $keys[] = $path;
            }
        }

        return $keys;
    }
}
