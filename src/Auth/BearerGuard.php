<?php

declare(strict_types=1);

namespace Kuman\Auth;

use InvalidArgumentException;
use Kuman\Request;
use Kuman\Response;

/**
 * Bearer tokens in the Authorization header, RFC 6750.
 */
class BearerGuard extends Guard
{
    protected const SCHEME = 'Bearer';

    /** An unset environment variable as array key would otherwise become the valid token "0". */
    private const MIN_LENGTH = 16;

    /** @var callable|array<int|string, mixed> */
    private $tokens;

    /**
     * @param callable|array<int|string, mixed> $tokens fn(string $token) returning the user, null
     *     or false to refuse. Or token => user.
     * @param ?string $query Also accept the token in this query parameter, for browser WebSocket
     *     and EventSource clients that cannot set headers. URLs end up in logs.
     */
    public function __construct(
        callable|array $tokens,
        private readonly string $realm = 'api',
        private readonly ?string $query = null,
    ) {
        if (!is_callable($tokens)) {
            foreach (array_keys($tokens) as $token) {
                if (strlen((string)$token) < self::MIN_LENGTH) {
                    throw new InvalidArgumentException('A fixed token needs at least ' . self::MIN_LENGTH . ' characters.');
                }
            }
        }
        $this->tokens = $tokens;
    }

    public function authenticate(Request $request): mixed
    {
        $token = $this->token($request);
        if ($token === null) {
            return null;
        }
        return is_callable($this->tokens) ? ($this->tokens)($token) : self::lookup($this->tokens, $token);
    }

    protected function token(Request $request): ?string
    {
        $pattern = '#^Bearer +([A-Za-z0-9\-._~+/]+=*)\z#i';
        return preg_match($pattern, (string)$request->header('authorization'), $match)
            ? $match[1]
            : $this->queryToken($request);
    }

    protected function queryToken(Request $request): ?string
    {
        // ?token[]=x arrives as an array.
        $token = $this->query === null ? null : $request->get($this->query);
        return is_string($token) && $token !== '' ? $token : null;
    }

    protected function challenge(Request $request): Response
    {
        $challenge = static::SCHEME . ' realm=' . self::quote($this->realm);
        if ($this->token($request) !== null) {
            $challenge .= ', error="invalid_token"';
        }
        return parent::challenge($request)->withHeader('WWW-Authenticate', $challenge);
    }
}
