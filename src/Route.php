<?php

declare(strict_types=1);

namespace Kuman;

/**
 * A route as it was defined. App builds the pipeline from it when the worker starts,
 * and OpenApi describes it.
 */
final class Route
{
    /** @var array<int, array> What Resolver found in the handler, empty for a plain handler. */
    public array $steps = [];

    /**
     * @param array<int, string> $methods
     * @param array<int, callable> $middleware
     * @param callable|array|string $handler The handler as given.
     * @param callable $callable The same handler after App::resolve.
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $path,
        public readonly array $middleware,
        public readonly mixed $handler,
        public readonly mixed $callable,
        public readonly bool $documented = true,
    ) {
    }

    /**
     * @return array<int, string> Names of the route parameters.
     */
    public function parameters(): array
    {
        $names = [];
        foreach (Router::parse($this->path) as $segments) {
            foreach ($segments as $segment) {
                if (is_array($segment)) {
                    $names[$segment[0]] = $segment[0];
                }
            }
        }
        return array_values($names);
    }
}
