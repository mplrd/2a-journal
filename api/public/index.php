<?php

declare(strict_types=1);

// ── Autoloader ──────────────────────────────────────────────────
require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\ErrorLogger;
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

// ── Routing ─────────────────────────────────────────────────────
try {
    $router = new Router();
    require __DIR__ . '/../config/routes.php';

    $request = Request::capture($securityConfig['trusted_proxies'] ?? []);
    $response = $router->dispatch($request);
    $response->send();
} catch (HttpException $e) {
    $response = Response::error($e->getErrorCode(), $e->getMessageKey(), $e->getField(), $e->getStatusCode());
    $response->send();
} catch (\Throwable $e) {
    // The cause goes to the server log and nowhere else. It used to go to the
    // *client* instead, behind APP_DEBUG — the only way to see why a 500
    // happened, which made a debug switch the price of diagnosing production.
    // Now that the exception is recorded here, that trade-off is gone: the
    // response carries no detail, in any environment, with no switch to get
    // wrong.
    ErrorLogger::logThrowable('api', 'unhandled_exception', $e, [
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'path' => ErrorLogger::redactPath($_SERVER['REQUEST_URI'] ?? null),
    ]);

    Response::error('INTERNAL_ERROR', 'error.internal', null, 500)->send();
}
