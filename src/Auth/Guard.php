<?php

declare(strict_types=1);

namespace Kuman\Auth;

use Kuman\Request;
use Kuman\Response;
use Workerman\Coroutine\Context;

/**
 * Middleware that lets a request through when it proves an identity, which handlers
 * read with $req->user(). One guard serves every request of a worker, so it keeps
 * nothing between them.
 */
abstract class Guard
{
    public const USER = 'user';

    private bool $optional = false;

    /** @var ?callable */
    private $onDeny = null;

    /**
     * The identity behind the request, null or false when it does not prove one.
     */
    abstract public function authenticate(Request $request): mixed;

    public function __invoke(Request $request, callable $next): mixed
    {
        $user = $this->authenticate($request);
        if ($user === null || $user === false) {
            return $this->optional ? $next($request) : $this->deny($request);
        }

        $request = $request->withAttribute(self::USER, $user);
        // Without this Request::current() would keep the copy that has no user.
        Context::set(Request::class, $request);
        return $next($request);
    }

    public function deny(Request $request): mixed
    {
        $response = $this->challenge($request);
        return $this->onDeny === null ? $response : ($this->onDeny)($request, $response);
    }

    /**
     * A copy that also lets anonymous requests through. Never this guard itself: it
     * may be protecting other routes.
     */
    public function optional(): static
    {
        $copy = clone $this;
        $copy->optional = true;
        return $copy;
    }

    /**
     * A copy that answers refused requests with fn(Request $request, Response $default).
     */
    public function onDeny(callable $handler): static
    {
        $copy = clone $this;
        $copy->onDeny = $handler;
        return $copy;
    }

    protected function challenge(Request $request): Response
    {
        return Response::json(['error' => 'unauthorized'], 401);
    }

    /**
     * Tries every entry, so the time taken does not tell which one matched.
     *
     * @param array<int|string, mixed> $known Secret => identity. PHP turns numeric keys into integers.
     */
    protected static function lookup(array $known, string $presented): mixed
    {
        $found = null;
        foreach ($known as $secret => $identity) {
            if (hash_equals((string)$secret, $presented)) {
                $found = $identity;
            }
        }
        return $found;
    }

    protected static function quote(string $value): string
    {
        return '"' . addcslashes($value, '"\\') . '"';
    }
}
