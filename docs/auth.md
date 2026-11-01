# Authentication

`Kuman\Auth` proves who is making a request. Each method of it builds a **guard**, which
is ordinary middleware: it lets the request through and records the user, or it answers
401. This covers authentication only. Deciding what a user may do is up to your
application.

- [Quick start](#quick-start)
- [Choosing a guard](#choosing-a-guard)
- [Attaching a guard](#attaching-a-guard)
- [Reading the user](#reading-the-user)
- [HTTP Basic](#http-basic)
- [Bearer tokens](#bearer-tokens)
- [API keys](#api-keys)
- [JSON Web Tokens](#json-web-tokens)
- [Sessions](#sessions)
- [Combining guards](#combining-guards)
- [Passwords and tokens](#passwords-and-tokens)
- [Things that trip people up](#things-that-trip-people-up)

## Quick start

```php
use Kuman\App;
use Kuman\Auth;
use Kuman\Auth\Jwt;
use Kuman\Request;
use Kuman\Response;

return function (App $app) {
    $jwt = new Jwt(getenv('JWT_SECRET'));       // at least 32 bytes

    $app->post('/login', function (Request $req) use ($jwt) {
        $user = findUser($req->post('email'));  // your own lookup
        if (!Auth::verify($req->post('password'), $user?->password)) {
            return Response::json(['error' => 'invalid credentials'], 401);
        }
        return ['token' => $jwt->issue(['sub' => $user->id])];
    });

    $app->group('/api', Auth::jwt($jwt), function (App $api) {
        $api->get('/me', fn(Request $req) => $req->user());
    });
};
```

## Choosing a guard

| Guard | Client sends | Good for |
|---|---|---|
| `Auth::basic()` | `Authorization: Basic ...` | Internal tools and staging, where the browser's own prompt is enough |
| `Auth::bearer()` | `Authorization: Bearer ...` | Opaque tokens you store and can revoke |
| `Auth::apiKey()` | `X-API-Key: ...` | Server-to-server calls |
| `Auth::jwt()` | `Authorization: Bearer <jwt>` | APIs that verify a token without a lookup, or tokens from an identity provider |
| `Auth::session()` | A session cookie | Browser applications with a login form |
| `Auth::any()` | Any of the above | One route used by a browser and by API clients |

Serve all of them over HTTPS: Basic passwords and tokens are readable on the wire.

## Attaching a guard

A guard is a callable, so it goes wherever middleware goes.

```php
$guard = Auth::bearer(fn(string $token) => findByToken($token));

$app->use('/admin', $guard);                         // a path prefix, static files included
$app->get('/me', $guard, $handler);                  // one route
$app->group('/api', $guard, function (App $api) {    // a group
    $api->get('/orders', $handler);
});
$app->ws('/chat', $guard, ['message' => $onMessage]);   // guards the upgrade
$app->sse('/events', $guard, $handler);
```

Only `$app->use()` outside a group protects files in `public/`. Route and group middleware
run after a route matched, and a static file has no route.

A guard can be shared between routes. `optional()` and `onDeny()` return a changed copy
and leave the guard you called them on as it was.

**Optional login.** The route also answers anonymous requests, and `$req->user()` is `null`
for them:

```php
$app->get('/feed', $guard->optional(), function (Request $req) {
    return $req->user() ? personalFeed($req->user()) : publicFeed();
});
```

**A custom refusal.** The handler gets the request and the default response, which already
carries the `WWW-Authenticate` header:

```php
$guard = $guard->onDeny(fn(Request $req, Response $default) => $default->withBody(Stream::from('Who are you?')));
```

## Reading the user

```php
$req->user();                        // what the guard resolved, null when no guard ran
Request::current()->user();          // for code that was not given $req
$req->getAttribute('user');          // the same value, through PSR-7
```

The user is whatever your callback returned: a row, an object, an id. In WebSocket `open`
and SSE handlers use the `$req` they receive.

## HTTP Basic

Give it a callback, or a map of usernames to `password_hash()` hashes. A plain password in
the map stops the worker at start.

```php
$app->use('/admin', Auth::basic(['admin' => getenv('ADMIN_HASH')], realm: 'Admin'));

$app->use('/admin', Auth::basic(function (string $username, string $password) {
    $user = findUser($username);
    return Auth::verify($password, $user?->password) ? $user : null;
}));
```

The callback returns the user, or `null` or `false` to refuse. Returning `true` makes the
username the user.

Basic checks the password on **every request**. `password_verify()` is slow on purpose, tens
to hundreds of milliseconds, and blocks the worker that long. For anything busy, log in once
and use a token or a session.

## Bearer tokens

```php
Auth::bearer(fn(string $token) => findByTokenHash(hash('sha256', $token)));

Auth::bearer([getenv('CI_TOKEN') => 'ci', getenv('DEPLOY_TOKEN') => 'deploy']);
```

The map compares in constant time. A key shorter than 16 characters stops the worker at
start, which also catches an unset environment variable.

Browsers cannot set headers on `WebSocket` and `EventSource`. Name a query parameter to
accept the token there too:

```php
$app->ws('/chat', Auth::bearer($tokens, query: 'token'), ['message' => $onMessage]);
```

URLs end up in access logs and browser history, so hand such clients a short-lived token.
A session cookie also works for them, without any of this.

## API keys

The same as bearer tokens, read from a header of their own:

```php
Auth::apiKey(fn(string $key) => findByKeyHash(hash('sha256', $key)));
Auth::apiKey([getenv('PARTNER_KEY') => 'partner'], header: 'X-Partner-Key');
Auth::apiKey($keys, query: 'api_key');                    // also ?api_key=...
```

## JSON Web Tokens

`Jwt` issues and verifies tokens, and `Auth::jwt()` reads them from the `Authorization`
header.

```php
$jwt = new Jwt(
    getenv('JWT_SECRET'),
    algorithm: 'HS256',
    ttl: 3600,                 // seconds, null for no expiry
    issuer: 'my-app',          // written to tokens and required of them
    audience: 'my-api',        // the same
    leeway: 30,                // seconds of clock difference to tolerate
);

$token = $jwt->issue(['sub' => $user->id, 'role' => $user->role]);
$claims = $jwt->verify($token);                   // an array, or null when anything is wrong

$app->use('/api', Auth::jwt($jwt));               // the user is the claims array
$app->use('/api', Auth::jwt($jwt, fn(array $claims) => findUser($claims['sub'])));
```

| Algorithms | Key | Rule |
|---|---|---|
| `HS256`, `HS384`, `HS512` | Shared secret | At least 32, 48 or 64 bytes |
| `RS256`, `RS384`, `RS512` | RSA key in PEM, needs `ext-openssl` | At least 2048 bits |
| `ES256`, `ES384` | P-256 or P-384 key in PEM, needs `ext-openssl` | The curve must match |

A key that breaks these rules stops the worker at start. With RS and ES, give the public
key to services that only verify: they can check tokens but `issue()` throws.

`verify()` takes the algorithm from your configuration and refuses a token that names
another one, including `none`. It also refuses `exp` in the past, `nbf` in the future, a
missing or different `iss` and `aud` when you configured them, and any `crit` header.
Tokens without `exp` are accepted, because the standard makes it optional.

The claims are signed, not encrypted, so anyone holding a token can read them. A JWT also
cannot be revoked before `exp`. Keep `ttl` short, or check something like the `jti` claim
against a list in the callback, which brings back the lookup a JWT usually avoids.

## Sessions

Check the password yourself, then call `Auth::login()` with an id. Sessions do not restore
objects, so store the id and load the user in the callback.

```php
$app->post('/login', function (Request $req) {
    $user = findUser($req->post('email'));
    if (!Auth::verify($req->post('password'), $user?->password)) {
        return Response::redirect('/login?failed=1');
    }
    Auth::login($req, $user->id);
    return Response::redirect('/dashboard');
});

$app->post('/logout', fn(Request $req) => Auth::logout($req, Response::redirect('/login')));

$pages = Auth::session(fn(int|string $id) => findUser($id), redirect: '/login');
$app->group('/dashboard', $pages, function (App $dashboard) {
    $dashboard->get('/', fn(Request $req) => 'Hello ' . $req->user()->name);
});
```

Without `redirect`, a refused request gets a 401. `Auth::login()` issues a new session id,
which stops an id planted in the browser before the login from being useful, and keeps the
rest of the session. `Auth::logout()` destroys the session, and returns the response you
gave it with the cookie removed. Return that response.

Kuman sets these session defaults when a worker starts, and `app.php` can change them
through the static properties of `Workerman\Protocols\Http\Session`:

| Setting | Default | Change it with |
|---|---|---|
| Storage | Files in `runtime/sessions/`, readable only by the owner | `Session::handlerClass()` |
| Cookie flags | `HttpOnly`, `SameSite=Lax` | `Session::$httpOnly`, `Session::$sameSite` |
| `Secure` flag | Off, so that plain HTTP works in development | `Session::$secure = true` |
| Idle timeout | `session.gc_maxlifetime` from `php.ini`, usually 24 minutes, renewed by each request | `Session::$lifetime` |

Turn `Secure` on in production:

```php
use Workerman\Protocols\Http\Session;

return function (App $app) {
    Session::$secure = true;
    Session::$lifetime = 7200;
    // ...
};
```

Files work across the workers of one machine. With several machines, use Redis:

```php
use Workerman\Protocols\Http\Session\RedisSessionHandler;

Session::handlerClass(RedisSessionHandler::class, ['host' => '127.0.0.1', 'port' => 6379]);
```

## Combining guards

`Auth::any()` tries each guard in order and the first that recognises the request wins. A
refused request gets the answer of the first guard.

```php
$app->group('/api', Auth::any(Auth::session($load), Auth::bearer($tokens)), function (App $api) {
    $api->get('/orders', $handler);
});
```

## Passwords and tokens

```php
$hash = Auth::hash($password);              // password_hash() with PHP's default algorithm
Auth::verify($password, $hash);             // true or false
Auth::verify($password, null);              // false, after the same amount of work
```

Pass `null` for an account that does not exist, as the examples above do with
`$user?->password`. The check then takes as long as a real one, so response times do not
reveal which accounts exist. `verify()` also takes any value, because
`$req->post('password')` is an array when a client sends `password[]=x`.

`Auth::token()` returns 64 random hex characters for API keys and the like. Show it to the
user once and store only `hash('sha256', $token)`, so that a leaked database holds no usable
tokens.

## Things that trip people up

- **Login attempts are not limited.** Kuman has no rate limiter. Put one in front of your
  login route, shared between workers, in Redis for example, or at the reverse proxy.
- **Cookies need CSRF thought.** `SameSite=Lax` stops other sites from sending `POST`
  requests with your session cookie. It does not cover a `GET` that changes something, so
  keep changes behind `POST`, `PUT` and `DELETE`.
- **Callbacks run on every request and block the worker.** A database lookup in a guard
  holds it like any other PDO query.
- **CORS preflights have no credentials.** An `OPTIONS` request under a guarded prefix gets
  a 401, so a CORS middleware must come before the guard.
- **Use the `$req` you are given.** The guard passes a copy with the user on it. A variable
  captured earlier, or the request a middleware holds from before the guard ran, has no
  user. `Request::current()` follows the guard, but it only exists during the request, not
  in a later timer.
