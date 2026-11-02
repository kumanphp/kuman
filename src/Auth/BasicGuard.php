<?php

declare(strict_types=1);

namespace Kuman\Auth;

use InvalidArgumentException;
use Kuman\Auth;
use Kuman\Request;
use Kuman\Response;

/**
 * HTTP Basic authentication, RFC 7617.
 */
class BasicGuard extends Guard
{
    /** @var callable|array<string, string> */
    private $users;

    /**
     * @param callable|array<string, string> $users fn(string $username, string $password) returning
     *     the user, true for the username, null or false to refuse. Or username => password_hash().
     */
    public function __construct(callable|array $users, private readonly string $realm = 'Restricted')
    {
        if (!is_callable($users)) {
            foreach ($users as $name => $hash) {
                if (!is_string($hash) || password_get_info($hash)['algo'] === null) {
                    throw new InvalidArgumentException("The password of $name must be a password_hash() hash.");
                }
            }
        }
        $this->users = $users;
    }

    public function authenticate(Request $request): mixed
    {
        $credentials = self::credentials((string)$request->header('authorization'));
        if ($credentials === null) {
            return null;
        }

        [$username, $password] = $credentials;
        if (is_callable($this->users)) {
            $user = ($this->users)($username, $password);
            return $user === true ? $username : $user;
        }
        return Auth::verify($password, $this->users[$username] ?? null) ? $username : null;
    }

    public function security(): array
    {
        return ['basicAuth' => ['type' => 'http', 'scheme' => 'basic']];
    }

    protected function challenge(Request $request): Response
    {
        return parent::challenge($request)
            ->withHeader('WWW-Authenticate', 'Basic realm=' . self::quote($this->realm) . ', charset="UTF-8"');
    }

    /**
     * @return ?array{0: string, 1: string} The password may hold colons, the username cannot.
     */
    private static function credentials(string $header): ?array
    {
        if (!preg_match('#^Basic +([A-Za-z0-9+/]+={0,2})\z#i', $header, $match)) {
            return null;
        }
        $decoded = base64_decode($match[1], true);
        if ($decoded === false || !str_contains($decoded, ':') || preg_match('/[\x00-\x1f\x7f]/', $decoded)) {
            return null;
        }
        return explode(':', $decoded, 2);
    }
}
