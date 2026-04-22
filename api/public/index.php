<?php

declare(strict_types=1);

// ── Autoloader ──────────────────────────────────────────────────
require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Exceptions\HttpException;

// ── .env parser ─────────────────────────────────────────────────
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (($eqPos = strpos($line, '=')) === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));
        // Strip surrounding quotes
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === $value[strlen($value) - 1]) {
            $value = substr($value, 1, -1);
        }
        if (!getenv($key)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

// ── CORS ────────────────────────────────────────────────────────
$corsConfig = require __DIR__ . '/../config/cors.php';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $corsConfig['origins'], true)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Methods: {$corsConfig['methods']}");
    header("Access-Control-Allow-Headers: {$corsConfig['headers']}");
    header("Access-Control-Max-Age: {$corsConfig['max_age']}");
    if (!empty($corsConfig['credentials'])) {
        header('Access-Control-Allow-Credentials: true');
    }
}

// ── Security headers ────────────────────────────────────────
$securityConfig = require __DIR__ . '/../config/security.php';
foreach ($securityConfig['headers'] as $name => $value) {
    header("$name: $value");
}

// Handle preflight
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── App config ──────────────────────────────────────────────────
$appConfig = require __DIR__ . '/../config/app.php';

// ── Routing ─────────────────────────────────────────────────────
try {
    $router = new Router();
    require __DIR__ . '/../config/routes.php';

    $request = Request::capture();
    $response = $router->dispatch($request);
    $response->send();
} catch (HttpException $e) {
    $response = Response::error($e->getErrorCode(), $e->getMessageKey(), $e->getField(), $e->getStatusCode());
    $response->send();
} catch (\Throwable $e) {
    $data = ['code' => 'INTERNAL_ERROR', 'message_key' => 'error.internal'];
    if ($appConfig['debug']) {
        $data['debug'] = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }
    $response = Response::error(
        $data['code'],
        $data['message_key'],
        null,
        500
    );
    // For debug mode, we need to build the response manually to include debug info
    if ($appConfig['debug']) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => $data,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        $response->send();
    }
}
