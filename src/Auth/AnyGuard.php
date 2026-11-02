<?php

declare(strict_types=1);

namespace Kuman\Auth;

use InvalidArgumentException;
use Kuman\Request;
use Kuman\Response;

/**
 * The first guard that recognises the request wins. A refused request gets the answer
 * of the first guard.
 */
class AnyGuard extends Guard
{
    /** @var array<int, Guard> */
    private array $guards;

    public function __construct(Guard ...$guards)
    {
        if ($guards === []) {
            throw new InvalidArgumentException('Auth::any() needs at least one guard.');
        }
        $this->guards = array_values($guards);
    }

    public function authenticate(Request $request): mixed
    {
        foreach ($this->guards as $guard) {
            $user = $guard->authenticate($request);
            if ($user !== null && $user !== false) {
                return $user;
            }
        }
        return null;
    }

    public function security(): array
    {
        return array_merge(...array_map(static fn(Guard $guard) => $guard->security(), $this->guards));
    }

    protected function challenge(Request $request): Response
    {
        return Response::from($this->guards[0]->deny($request));
    }
}
