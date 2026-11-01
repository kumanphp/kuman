<?php

declare(strict_types=1);

namespace Kuman;

use Kuman\Auth\AnyGuard;
use Kuman\Auth\ApiKeyGuard;
use Kuman\Auth\BasicGuard;
use Kuman\Auth\BearerGuard;
use Kuman\Auth\Guard;
use Kuman\Auth\Jwt;
use Kuman\Auth\SessionGuard;
use SensitiveParameter;
use Workerman\Protocols\Http\Session;

/**
 * Guards are middleware: $app->use('/admin', Auth::basic($users)). Handlers read the
 * identity with $req->user().
 */
final class Auth
{
    private static ?string $decoy = null;

    /**
     * @param callable|array<string, string> $users See BasicGuard.
     */
    public static function basic(callable|array $users, string $realm = 'Restricted'): BasicGuard
    {
        return new BasicGuard($users, $realm);
    }

    /**
     * @param callable|array<int|string, mixed> $tokens See BearerGuard.
     */
    public static function bearer(callable|array $tokens, string $realm = 'api', ?string $query = null): BearerGuard
    {
        return new BearerGuard($tokens, $realm, $query);
    }

    /**
     * @param callable|array<int|string, mixed> $keys See ApiKeyGuard.
     */
    public static function apiKey(callable|array $keys, string $header = 'X-API-Key', ?string $query = null): ApiKeyGuard
    {
        return new ApiKeyGuard($keys, $header, $query);
    }

    /**
     * The user is the claims array, or what fn(array $claims) returns from them.
     */
    public static function jwt(Jwt $jwt, ?callable $resolve = null, string $realm = 'api', ?string $query = null): BearerGuard
    {
        return new BearerGuard(static function (string $token) use ($jwt, $resolve): mixed {
            $claims = $jwt->verify($token);
            return $claims === null || $resolve === null ? $claims : $resolve($claims);
        }, $realm, $query);
    }

    /**
     * @param ?callable $resolve fn(int|string $id) returning the user, null or false to refuse.
     * @param ?string $redirect Send refused requests here instead of answering 401.
     */
    public static function session(?callable $resolve = null, ?string $redirect = null): SessionGuard
    {
        return new SessionGuard($resolve, $redirect);
    }

    public static function any(Guard ...$guards): AnyGuard
    {
        return new AnyGuard(...$guards);
    }

    /**
     * Log in through the session, after checking the credentials. Keep it to an id: sessions
     * do not restore objects.
     */
    public static function login(Request $request, int|string $id): void
    {
        // A new id, so that an id planted in the victim's browser before the login stays worthless.
        $request->sessionRegenerateId(true);

        // Sessions are otherwise written when the request ends, after the response has
        // left: the next request of the client could reach another worker first.
        $session = $request->session();
        $session->set(SessionGuard::KEY, $id);
        $session->save();
    }

    /**
     * End the session login, and return $response with the session cookie removed.
     */
    public static function logout(Request $request, mixed $response): Response
    {
        if ($request->isValidSessionId($request->cookie(Session::$name))) {
            $session = $request->session();
            $session->flush();
            $session->save();
        }

        $params = Session::getCookieParams();
        return Response::from($response)->withCookie(Session::$name, '', 0, $params['path'], $params['domain']);
    }

    public static function hash(#[SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Pass null as the hash when the account does not exist: the same work runs, so the
     * time of the answer does not tell which accounts do. The password is mixed because
     * it comes from the client, and $req->post('password') can be an array.
     */
    public static function verify(#[SensitiveParameter] mixed $password, ?string $hash): bool
    {
        $known = $hash !== null && $hash !== '';
        $hash = $known ? $hash : self::$decoy ??= self::hash('');
        return is_string($password) && password_verify($password, $hash) && $known;
    }

    /**
     * A random token for API keys and the like. Store hash('sha256', $token), not the token.
     */
    public static function token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
