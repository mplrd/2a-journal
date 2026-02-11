<?php

namespace App\Core;

class Request
{
    private string $method;
    private string $uri;
    private array $body;
    private array $query;
    private array $headers;
    private array $routeParams;
    private array $attributes;
    private string $clientIp;

    private function __construct(string $method, string $uri, array $body, array $query, array $headers, string $clientIp = '127.0.0.1')
    {
        $this->method = $method;
        $this->uri = $uri;
        $this->body = $body;
        $this->query = $query;
        $this->headers = $headers;
        $this->routeParams = [];
        $this->attributes = [];
        $this->clientIp = $clientIp;
    }

    public static function capture(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Strip query string from URI
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }

        // Strip /api prefix (Apache Alias keeps it in REQUEST_URI)
        if (str_starts_with($uri, '/api')) {
            $uri = substr($uri, 4) ?: '/';
        }

        // Parse body
        $body = [];
        $rawBody = file_get_contents('php://input');
        if ($rawBody !== false && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        // Parse headers
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[$headerName] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['CONTENT-TYPE'] = $_SERVER['CONTENT_TYPE'];
        }
        // Apache may strip Authorization and expose it via REDIRECT_ prefix after rewrite
        if (!isset($headers['AUTHORIZATION']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        return new self($method, $uri, $body, $_GET, $headers, $clientIp);
    }

    public static function create(string $method, string $uri, array $body = [], array $query = [], array $headers = []): self
    {
        // Normalize header keys to uppercase
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtoupper(str_replace('_', '-', $key))] = $value;
        }
        return new self($method, $uri, $body, $query, $normalized);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getBody(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }
        return $this->body[$key] ?? $default;
    }

    public function getQuery(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    public function getHeader(string $name): ?string
    {
        $name = strtoupper(str_replace('_', '-', $name));
        return $this->headers[$name] ?? null;
    }

    public function getRouteParams(): array
    {
        return $this->routeParams;
    }

    public function getRouteParam(string $name, mixed $default = null): mixed
    {
        return $this->routeParams[$name] ?? $default;
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function getClientIp(): string
    {
        return $this->clientIp;
    }
}
