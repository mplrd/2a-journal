<?php

namespace App\Core;

use App\Exceptions\NotFoundException;

class Router
{
    private array $routes = [];

    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    public function patch(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    private function addRoute(string $method, string $path, callable|array $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $uri = $request->getUri();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->matchPath($route['path'], $uri);
            if ($params !== null) {
                $request->setRouteParams($params);
                $this->runMiddleware($route['middleware'] ?? [], $request);
                return $this->callHandler($route['handler'], $request);
            }
        }

        throw new NotFoundException();
    }

    private function matchPath(string $pattern, string $uri): ?array
    {
        // Convert route pattern to regex
        // e.g., /users/{id} → /users/([^/]+)
        $paramNames = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_]+)\}/', function ($matches) use (&$paramNames) {
            $paramNames[] = $matches[1];
            return '([^/]+)';
        }, $pattern);

        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $uri, $matches)) {
            return null;
        }

        // Build params array
        $params = [];
        foreach ($paramNames as $i => $name) {
            $params[$name] = $matches[$i + 1];
        }

        return $params;
    }

    private function runMiddleware(array $middleware, Request $request): void
    {
        foreach ($middleware as $mw) {
            if ($mw instanceof MiddlewareInterface) {
                $mw->handle($request);
            }
        }
    }

    private function callHandler(callable|array $handler, Request $request): Response
    {
        if (is_array($handler)) {
            [$controller, $method] = $handler;
            if (is_string($controller)) {
                $controller = new $controller();
            }
            return $controller->$method($request);
        }

        return $handler($request);
    }
}
