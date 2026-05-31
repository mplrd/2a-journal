<?php

namespace App\Core;

use App\Exceptions\ValidationException;

abstract class Controller
{
    /**
     * Reject a multipart request whose body PHP discarded for exceeding
     * post_max_size (empty $_POST/$_FILES despite bytes on the wire) with a
     * clean "too large" error rather than a misleading downstream failure.
     */
    protected function guardUploadNotTruncated(Request $request): void
    {
        if ($request->isMultipartTruncated()) {
            throw new ValidationException('upload.error.too_large', 'attachments');
        }
    }

    protected function jsonSuccess(array $data = [], ?array $meta = null, int $status = 200): Response
    {
        return Response::success($data, $meta, $status);
    }

    protected function jsonError(string $code, string $messageKey, ?string $field = null, int $status = 400): Response
    {
        return Response::error($code, $messageKey, $field, $status);
    }

    /**
     * Normalize a $_FILES entry into a flat list of single-file arrays,
     * transparently handling both `name="field"` (one file) and
     * `name="field[]"` (PHP pivots the arrays per key). Entries with no file
     * (UPLOAD_ERR_NO_FILE) are dropped.
     *
     * @param array<string,mixed>|null $entry  result of Request::getFile()
     * @return array<int, array<string,mixed>>
     */
    protected function normalizeUploadedFiles(?array $entry): array
    {
        if (empty($entry)) {
            return [];
        }

        // Multi-file: $entry['name'] is itself an array
        if (isset($entry['name']) && is_array($entry['name'])) {
            $files = [];
            foreach (array_keys($entry['name']) as $i) {
                if (($entry['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $files[] = [
                    'name' => $entry['name'][$i] ?? '',
                    'type' => $entry['type'][$i] ?? '',
                    'tmp_name' => $entry['tmp_name'][$i] ?? '',
                    'error' => $entry['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $entry['size'][$i] ?? 0,
                ];
            }

            return $files;
        }

        // Single file
        if (($entry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }

        return [$entry];
    }
}
