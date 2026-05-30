<?php

namespace App\Services;

use App\Exceptions\ValidationException;

/**
 * Generic, reusable uploaded-file handler. Validates a single $_FILES entry
 * (presence, size, real MIME type via finfo — never trusting the client header)
 * and persists it under a base directory with a non-guessable filename.
 *
 * Returns only metadata; the caller decides what to do with `stored_path`
 * (relative to the base dir). Used by the support module to keep ticket
 * attachments OUTSIDE the public web root, served through an authenticated
 * endpoint rather than a static URL.
 */
class FileUploadService
{
    private string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/\\');
    }

    /**
     * @param array<string,mixed> $file        a $_FILES entry
     * @param string              $subdir      sub-directory under the base dir (e.g. 'tickets')
     * @param array<string,string> $mimeToExt  whitelist: real-mime => extension
     * @param int                 $maxBytes    max accepted size
     * @param string              $field       form field name, surfaced in validation errors
     * @return array{stored_path:string, original_name:string, mime_type:string, size_bytes:int}
     */
    public function store(array $file, string $subdir, array $mimeToExt, int $maxBytes, string $field = 'file'): array
    {
        if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException('upload.error.required', $field);
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size > $maxBytes) {
            throw new ValidationException('upload.error.too_large', $field);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']) ?: '';
        if (!isset($mimeToExt[$mimeType])) {
            throw new ValidationException('upload.error.invalid_type', $field);
        }

        $ext = $mimeToExt[$mimeType];
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;

        $targetDir = "{$this->baseDir}/{$subdir}";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $destination = "{$targetDir}/{$filename}";

        // move_uploaded_file in production; copy() keeps the helper testable with
        // ordinary temp files (same fallback as AuthService::uploadProfilePicture).
        if (is_uploaded_file($file['tmp_name'])) {
            move_uploaded_file($file['tmp_name'], $destination);
        } else {
            copy($file['tmp_name'], $destination);
        }

        return [
            'stored_path' => "{$subdir}/{$filename}",
            'original_name' => basename((string) ($file['name'] ?? $filename)),
            'mime_type' => $mimeType,
            'size_bytes' => $size,
        ];
    }

    /** Absolute path on disk for a previously-returned stored_path. */
    public function absolutePath(string $storedPath): string
    {
        return "{$this->baseDir}/" . ltrim($storedPath, '/\\');
    }

    /** Best-effort removal of a stored file (e.g. on rollback). */
    public function delete(string $storedPath): void
    {
        $path = $this->absolutePath($storedPath);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
