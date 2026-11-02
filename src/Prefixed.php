<?php

declare(strict_types=1);

namespace Kuman;

/**
 * Middleware limited to a path prefix, kept as an object so the OpenAPI document can
 * tell which routes a guard covers.
 */
final class Prefixed
{
    /** @var callable */
    public readonly mixed $middleware;

    public function __construct(public readonly string $prefix, callable $middleware)
    {
        $this->middleware = $middleware;
    }

    public function __invoke(Request $request, callable $next): mixed
    {
        // Static files are looked up by decoded path, so compare the same form.
        $path = preg_replace('~/+~', '/', rawurldecode($request->path()));
        return $this->covers($path) ? ($this->middleware)($request, $next) : $next($request);
    }

    public function covers(string $path): bool
    {
        return $path === $this->prefix || str_starts_with($path, "$this->prefix/");
    }
}
