<?php

namespace Tests\Unit\Services;

use App\Exceptions\ValidationException;
use App\Services\FileUploadService;
use PHPUnit\Framework\TestCase;

class FileUploadServiceTest extends TestCase
{
    private string $baseDir;

    /** mime => extension map shared by the assertions below */
    private const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/support_uploads_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->baseDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "$dir/$entry";
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** 1x1 transparent PNG */
    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
    }

    private function makeUpload(string $content, string $name, ?int $sizeOverride = null): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $content);

        return [
            'name' => $name,
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => $sizeOverride ?? strlen($content),
        ];
    }

    public function testStoreValidImageReturnsMetadataAndPersistsFile(): void
    {
        $service = new FileUploadService($this->baseDir);
        $file = $this->makeUpload($this->pngBytes(), 'screenshot.png');

        $meta = $service->store($file, 'tickets', self::IMAGE_TYPES, 2 * 1024 * 1024, 'attachment');

        $this->assertSame('screenshot.png', $meta['original_name']);
        $this->assertSame('image/png', $meta['mime_type']);
        $this->assertGreaterThan(0, $meta['size_bytes']);
        $this->assertStringStartsWith('tickets/', $meta['stored_path']);
        $this->assertStringEndsWith('.png', $meta['stored_path']);
        $this->assertFileExists($this->baseDir . '/' . $meta['stored_path']);
    }

    public function testStoreGeneratesNonGuessableFilename(): void
    {
        $service = new FileUploadService($this->baseDir);

        // Distinctive original name so the "doesn't leak the original name" check
        // can't match by coincidence on the random hex (a short name like "a.png"
        // is a substring of e.g. "...efba.png" ~1/16 of the time → flaky).
        $original = 'my-secret-screenshot.png';
        $a = $service->store($this->makeUpload($this->pngBytes(), $original), 'tickets', self::IMAGE_TYPES, 2_000_000, 'attachment');
        $b = $service->store($this->makeUpload($this->pngBytes(), $original), 'tickets', self::IMAGE_TYPES, 2_000_000, 'attachment');

        // Same original name, but stored paths must differ (no collision / no enumeration)
        $this->assertNotSame($a['stored_path'], $b['stored_path']);
        // Stored name is random hex, never derived from the original name.
        $this->assertStringNotContainsString('my-secret-screenshot', $a['stored_path']);
        $this->assertMatchesRegularExpression('#^tickets/[0-9a-f]{32}\.png$#', $a['stored_path']);
    }

    public function testStoreRejectsMissingFile(): void
    {
        $service = new FileUploadService($this->baseDir);

        $this->expectException(ValidationException::class);
        $service->store(['error' => UPLOAD_ERR_NO_FILE], 'tickets', self::IMAGE_TYPES, 2_000_000, 'attachment');
    }

    public function testStoreRejectsOversizeFile(): void
    {
        $service = new FileUploadService($this->baseDir);
        $file = $this->makeUpload($this->pngBytes(), 'big.png', 5 * 1024 * 1024);

        try {
            $service->store($file, 'tickets', self::IMAGE_TYPES, 1024, 'attachment');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('attachment', $e->getField());
        }
    }

    public function testStoreRejectsDisallowedMimeType(): void
    {
        $service = new FileUploadService($this->baseDir);
        // Real content is plain text → finfo detects text/plain, not an allowed image
        $file = $this->makeUpload('just some text, not an image', 'evil.png');

        $this->expectException(ValidationException::class);
        $service->store($file, 'tickets', self::IMAGE_TYPES, 2_000_000, 'attachment');
    }
}
