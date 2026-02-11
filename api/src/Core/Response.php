<?php

namespace App\Core;

class Response
{
    private int $statusCode;
    private array $body;
    private array $headers = [];

    private function __construct(int $statusCode, array $body)
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
    }

    public static function success(array $data = [], ?array $meta = null, int $status = 200): self
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
        header('Content-Type: application/json; charset=utf-8');
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo json_encode($this->body, JSON_UNESCAPED_UNICODE);
    }
}
