<?php

declare(strict_types=1);

namespace Kuman\Auth;

use Kuman\Request;

/**
 * A key in a header of its own, X-API-Key unless told otherwise.
 */
class ApiKeyGuard extends BearerGuard
{
    protected const SCHEME = 'ApiKey';

    /**
     * @param callable|array<int|string, mixed> $keys fn(string $key) returning the user, null or
     *     false to refuse. Or key => user.
     */
    public function __construct(
        callable|array $keys,
        private readonly string $header = 'X-API-Key',
        ?string $query = null,
        string $realm = 'api',
    ) {
        parent::__construct($keys, $realm, $query);
    }

    protected function token(Request $request): ?string
    {
        $key = (string)$request->header($this->header);
        return $key !== '' ? $key : $this->queryToken($request);
    }
}
