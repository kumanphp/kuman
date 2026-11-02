<?php

declare(strict_types=1);

namespace Kuman;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;

use function FastRoute\simpleDispatcher;

/**
 * Express-style paths on top of FastRoute: /users/:id, /users/:id(\d+) and /files/*.
 */
final class Router
{
    /** Paths come from clients, so the match cache must stay bounded. */
    private const CACHE_SIZE = 1024;

    /** @var array<int, array{0: array<int, string>, 1: string, 2: callable}> */
    private array $routes = [];

    /** @var array<string, array{0: int, 1: callable, 2: array<string, string>}> */
    private array $cache = [];

    private ?Dispatcher $dispatcher = null;

    /**
     * @param array<int, string> $methods
     */
    public function add(array $methods, string $path, callable $handler): void
    {
        $this->routes[] = [$methods, self::pattern($path), $handler];
    }

    public function compile(): void
    {
        $this->dispatcher = simpleDispatcher(function (RouteCollector $collector): void {
            foreach ($this->routes as [$methods, $pattern, $handler]) {
                $collector->addRoute($methods, $pattern, $handler);
            }
        });
    }

    /**
     * @return array{0: int, 1?: mixed, 2?: array<string, string>} A FastRoute dispatch result.
     */
    public function match(string $method, string $path): array
    {
        $path = rtrim($path, '/') ?: '/';
        $key = "$method $path";
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $match = $this->dispatcher->dispatch($method, $path);
        if ($match[0] === Dispatcher::FOUND) {
            $match[2] = array_map('rawurldecode', $match[2]);
            $this->cache[$key] = $match;
            if (count($this->cache) > self::CACHE_SIZE) {
                unset($this->cache[array_key_first($this->cache)]);
            }
        }
        return $match;
    }

    /**
     * @return array<int, array<int, string|array{0: string, 1: string}>> A list of variants, more
     *     than one with optional segments. Each is made of literal text and [name, regex] pairs.
     */
    public static function parse(string $path): array
    {
        return (new Std())->parse(self::pattern($path));
    }

    private static function pattern(string $path): string
    {
        $path = rtrim('/' . ltrim($path, '/'), '/') ?: '/';
        $path = preg_replace_callback(
            '~:(\w+)(?:\(([^/]+)\))?~',
            static fn(array $m) => isset($m[2]) ? "{{$m[1]}:$m[2]}" : "{{$m[1]}}",
            $path
        );
        return preg_replace('~/\*$~', '/{wildcard:.*}', $path);
    }
}
