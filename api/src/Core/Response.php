<?php

namespace App\Core;

class Response
{
    private int $statusCode;
    /** @var array{success: bool, data?: array|null, error?: array, meta?: array} */
    private array $body;
    private array $headers = [];

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
        if (!isset($this->headers['Location'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->body, JSON_UNESCAPED_UNICODE);
        }
    }
}
