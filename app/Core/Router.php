<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function add(
        string $method,
        string $path,
        callable $handler,
        bool $admin = false,
        ?string $rate = null,
    ): void {
        $parts = explode('/', $path);
        $pattern = implode(
            '/',
            array_map(
                static fn($part) => preg_match('/^\{[a-zA-Z]+\}$/D', $part)
                    ? '([^/]+)'
                    : preg_quote($part, '~'),
                $parts,
            ),
        );
        $this->routes[] = [$method, '~^' . $pattern . '$~D', $handler, $admin, $rate];
    }

    public function dispatch(
        Request $request,
        callable $authorize,
        callable $rateLimit,
    ): array {
        foreach ($this->routes as [$method, $pattern, $handler, $admin, $rate]) {
            if (
                $method !== $request->method ||
                !preg_match($pattern, $request->path, $matches)
            ) {
                continue;
            }
            if ($admin) {
                $authorize($request);
            }
            if ($rate !== null) {
                $rateLimit($request, $rate);
            }
            return $handler(...array_map('rawurldecode', array_slice($matches, 1)));
        }
        throw new HttpException(404, 'Endpoint tidak ditemukan.');
    }
}
