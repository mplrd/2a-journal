<?php

namespace App\Core;

class Response
{
    private int $statusCode;
    /** @var array{success: bool, data?: array|null, error?: array, meta?: array} */
    private array $body;
    private array $headers = [];
    /** Bytes sent as-is instead of the JSON envelope — set by download() only. */
    private ?string $rawBody = null;

    private function __construct(int $statusCode, array $body)
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
    }

    /**
     * `$data` is nullable because "found nothing, and that is a normal answer"
     * is a real case — asking an account whether it has a broker connection,
     * for one. Typed as a plain array, that answer raised a TypeError and a
     * legitimate 200 came out as a 500.
     *
     * Null rather than an empty array on purpose: `[]` is truthy in JavaScript,
     * so a client would read "nothing here" as "here is something".
     */
    public static function success(?array $data = [], ?array $meta = null, int $status = 200): self
    {
        $body = ['success' => true, 'data' => $data];
        if ($meta !== null) {
            $body['meta'] = $meta;
        }
        return new self($status, $body);
    }

    public static function error(string $code, string $messageKey, ?string $field = null, int $status = 400): self
    {
        $error = [
            'code' => $code,
            'message_key' => $messageKey,
        ];
        if ($field !== null) {
            $error['field'] = $field;
        }
        return new self($status, ['success' => false, 'error' => $error]);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        $response = new self($status, []);
        $response->headers['Location'] = $url;
        return $response;
    }

    /**
     * A file the browser saves rather than JSON. Built here, not with
     * header() + exit in the controller, so a test can read what was sent.
     */
    public static function download(string $bytes, string $mimeType, string $filename): self
    {
        // The name lands in a header: strip quotes and line breaks (injection guard).
        $safeName = preg_replace('/["\r\n]+/', '', $filename);

        $response = new self(200, []);
        $response->rawBody = $bytes;
        $response->headers['Content-Type'] = $mimeType;
        $response->headers['Content-Disposition'] = 'attachment; filename="' . $safeName . '"';
        $response->headers['Content-Length'] = (string) strlen($bytes);
        $response->headers['X-Content-Type-Options'] = 'nosniff';
        return $response;
    }

    public function getRawBody(): ?string
    {
        return $this->rawBody;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): array
    {
        return $this->body;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if ($this->rawBody !== null) {
            echo $this->rawBody;
            return;
        }
        if (!isset($this->headers['Location'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->body, JSON_UNESCAPED_UNICODE);
        }
    }
}
