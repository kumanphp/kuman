<p align="center">
  <img src="https://image.web.id/images/logo-kuman.png" alt="Kuman logo" width="160">
</p>

# Kuman

A PHP microframework for long-running processes, built directly on
[Workerman](https://github.com/walkor/workerman) with Express-style routing.
One route file, no `config/` folder, and HTTP, WebSocket and SSE all run on a
single port.

Requires PHP 8.2+ with the `pcntl` and `posix` extensions (Linux or macOS).

## Getting started

Start a new project from the [skeleton](https://github.com/kumanphp/app):

```bash
composer create-project kumanphp/app myapp
cd myapp
vendor/bin/kuman
```

Then open <http://localhost:8642>. Define your routes in `app.php`.

### Add to an existing project

```bash
composer require kumanphp/kuman
```

Create `app.php`:

```php
<?php

use Kuman\App;
use Kuman\Request;

return function (App $app) {
    $app->get('/', fn() => 'Hello from Kuman');
    $app->get('/users/:id', fn(Request $req) => ['id' => $req->param('id')]);
};
```

Run it, then open <http://localhost:8642>:

```bash
vendor/bin/kuman
```

When running in the foreground, Kuman reloads the workers automatically whenever
a PHP file changes.

## Routing

```php
$app->get('/users/:id', $handler);          // also post, put, patch, delete, options
$app->all('/any', $handler);                // any method
$app->route(['GET', 'POST'], '/form', $handler);

$app->get('/users/:id(\d+)', $handler);     // parameter with a regex
$app->get('/files/*', $handler);            // $req->param('wildcard')
```

Register static routes before the dynamic routes that would shadow them, for
example `/users/me` before `/users/:id`. The reverse order makes the worker fail
to start and restart in a loop, with the error shown in the terminal or in
`runtime/kuman.log`.

Group routes under a prefix. The last argument defines the routes, the ones before it
are middleware for the whole group. Groups nest, and `use()` inside a group applies to
the routes defined after it in that group.

```php
$app->group('/api', $auth, function (App $api) {
    $api->get('/orders', $handler);                  // GET /api/orders
    $api->group('/v1', function (App $v1) {
        $v1->get('/orders', $handler);               // GET /api/v1/orders
    });
});
```

### Controllers

A handler can be a controller method, or an invokable class. Anything before the last
handler of a route is route middleware, and it can be a controller method too.

```php
$app->get('/users/:id', [UserController::class, 'show']);
$app->post('/users', [UserController::class, 'create']);
$app->get('/health', HealthController::class);       // has __invoke()

$app->group('/admin', $auth, function (App $admin) {
    $admin->get('/users/:id', [UserController::class, 'show']);
});

class UserController
{
    public function show(Request $req)
    {
        return ['id' => $req->param('id')];          // returns like any handler
    }
}
```

Kuman creates the controller **for each request**, so a property never carries data from
one request to the next. A controller needs a constructor without required arguments;
for anything else pass an instance, `[$controller, 'show']`, and it keeps its state.
A missing class or method, or a private method, stops the worker at start, like a
routing mistake, and does not fail later in a request. `ws()` still takes callbacks.

## Handlers and responses

> Every method of `Request` and `Response`, with examples and a step-by-step tutorial:
> [docs/request-response.md](docs/request-response.md).

A handler receives a `Kuman\Request` and **returns** a response:

```php
fn() => 'text';                        // HTML
fn() => ['ok' => true];                // JSON
fn() => Response::json($data, 201);
fn() => Response::text('text', 400);
fn() => Response::redirect('/login');
fn(Request $req) => Response::file('/path/report.pdf', $req);
```

Available on the request: `param()`, `get()`, `post()`, `input()`, `all()`,
`header()`, `cookie()`, `file()`, `ip()`, `user()` and `connection`. JSON bodies are parsed
automatically and read through `post()`. Code that does not receive `$req` can use
`Request::current()`.

A handler that declares a second parameter also receives an empty `Response` to fill
and the route parameters, as in Slim:

```php
$app->get('/hello/{name}', function ($request, $response, array $args) {
    $response->getBody()->write("Hello, {$args['name']}");
    return $response->withStatus(201);
});
```

`{name}` and `:name` are the same, and `$args` is `$req->params`. Write to the body
before you derive a copy with `withStatus()` or `withHeader()`, or return the copy you
wrote to. A handler with one parameter, or none, is called as before. Only the last
handler of a route gets this; route middleware keeps `($request, $next)`. As always, the
handler must **return** the response: without a `return`, Kuman waits for a later
`respond()`.

`null` means the handler keeps the connection and will answer later. A handler
that forgets to `return` leaves the client waiting forever.

```php
$app->get('/slow', function (Request $req) use ($app) {
    $app->after(2, fn() => $req->respond(['done' => true]));
    return null;
});
```

## Middleware

Middleware receives the request and `$next`. `$next` always returns a response,
or `null` for a connection held by the handler (WebSocket, SSE, deferred
response). Use `?->` when modifying the result.

```php
$logger = function (Request $req, callable $next) {
    $start = microtime(true);
    $res = $next($req);
    return $res?->withHeader('X-Time', (string)(microtime(true) - $start));
};

$auth = fn(Request $req, callable $next) => $req->header('x-token') === 'secret'
    ? $next($req)
    : Response::json(['error' => 'unauthorized'], 401);

$app->use($logger);                     // every request, including 404s
$app->use('/admin', $auth);             // one path prefix
$app->get('/me', $auth, $handler);      // one route

$app->group('/api', $auth, function (App $api) {
    $api->get('/orders', fn() => []);
});
```

## Authentication

> Every guard and option, with sessions, JWT keys and common mistakes:
> [docs/auth.md](docs/auth.md).

`Kuman\Auth` builds guards, which are middleware. A guard lets the request through and
records the user, or answers 401.

```php
$jwt = new Jwt(getenv('JWT_SECRET'));

$app->use('/admin', Auth::basic(['admin' => $passwordHash]));
$app->use('/hooks', Auth::apiKey([getenv('PARTNER_KEY') => 'partner']));
$app->group('/api', Auth::jwt($jwt), function (App $api) {
    $api->get('/me', fn(Request $req) => $req->user());
});
$app->get('/feed', Auth::bearer($findByToken)->optional(), $handler);
$app->get('/dashboard', Auth::session($loadUser, redirect: '/login'), $handler);
$app->get('/orders', Auth::any(Auth::session($loadUser), Auth::jwt($jwt)), $handler);
```

- `Auth::verify($password, $user?->password)` checks a password, and takes the same time
  when the account does not exist. `Auth::hash()` makes the hash.
- `$jwt->issue(['sub' => $id])` makes a token. HS, RS and ES algorithms are supported.
- `Auth::login($req, $id)` starts a session login with a new session id, and
  `Auth::logout($req, $response)` ends it.
- Login attempts are not rate limited: put a limiter in front of your login route.

## PSR-7

`Kuman\Request` implements `Psr\Http\Message\ServerRequestInterface` and
`Kuman\Response` implements `ResponseInterface`, so PSR-7 code and middleware
libraries work with them. Both are immutable: every `with*()` call returns a copy.

```php
$app->use(function (Request $req, callable $next) {
    $res = $next($req->withAttribute('start', microtime(true)));
    return $res?->withHeader('X-Time', (string)(microtime(true) - $req->getAttribute('start')));
});

$app->post('/upload', function (Request $req) {
    $req->getUploadedFiles()['avatar']->moveTo('/srv/avatars/me.png');
    return (new Response())->withStatus(201)->withBody(Stream::from('saved'));
});
```

- A handler can return any PSR-7 response, from Kuman or another library. Its body is
  read when the response is sent.
- A copy of the request stays in step with the Kuman accessors: after `withUri()`,
  `withRequestTarget()`, `withMethod()`, `withHeader()` or `withQueryParams()`, the
  router and `path()`, `method()`, `header()` and `get()` see the new value.
  `post()`, `file()`, `input()` and `all()` keep reading the received body, so use
  `getParsedBody()` and `getUploadedFiles()` after `withParsedBody()` or
  `withUploadedFiles()`. `withBody()` is the exception: it replaces the body for both.
- `Request::current()` and `$req->connection` belong to the request Kuman received,
  not to a copy. The exception is an authentication guard, which makes the copy holding
  the user the current request. Call `respond()` on the original or on a copy, both work.
- Response headers are validated: a name with spaces or a value with a line break
  throws `InvalidArgumentException`, which becomes a 500.
- Request header names come lowercased from Workerman, and repeated headers are
  already joined with a comma in a single value.
- Replace Workerman's mutating `header()` on responses with `withHeader()`, and
  `cookie()` with `withCookie()`.

## WebSocket

```php
$app->ws('/chat', [
    'open' => fn($conn, Request $req) => $conn->send('hello'),
    'message' => fn($conn, string $data) => $conn->send($data),
    'close' => fn($conn) => null,
]);

$app->ws('/private', $auth, ['message' => $onMessage]);   // middleware guards the upgrade
```

## Server-Sent Events

Timers created with `$sse->every()` stop automatically when the client
disconnects.

```php
$app->sse('/time', function (Request $req, Sse $sse) {
    $sse->send('connected', event: 'hello');
    $sse->every(1, fn() => $sse->send(['time' => date('c')]));
    $sse->onClose(fn() => null);
});
```

## Timers and state

The closure in `app.php` runs once per worker after the fork, which makes it the
right place to open database connections and keep state. Values persist across
requests, but only within that worker.

```php
return function (App $app) {
    $db = new PDO($dsn);        // one connection per worker

    $app->every(60, fn() => $db->query('SELECT 1'));   // first worker only
};
```

Exceptions in requests and in Kuman's own callbacks are logged without killing the
worker. Wrap any other callback, such as a direct `Timer::add()`, with
`$app->guard($fn)`. Error pages can be replaced with `$app->onError()` and
`$app->notFound()`.

## Static files

Files in `public/` are served automatically when no route matches, with support
for `Range` and `If-Modified-Since`. `.php` files and dotfiles are never served,
except for `.well-known/`.

## Configuration

The defaults are enough to get started. Precedence, from lowest to highest:
`kuman.php`, environment variables, then flags.

| Setting | Flag | Env | Default |
|---|---|---|---|
| Port | `--port` | `PORT` | `8642` |
| Host | `--host` | `KUMAN_HOST` | `0.0.0.0` |
| Worker count | `--workers` | `WORKERS` | CPU count |
| Event loop | `--loop` | `KUMAN_LOOP` | automatic |
| Error details in responses | `--debug` | `KUMAN_DEBUG` | off |
| Automatic reload | | `KUMAN_WATCH` | on in the foreground |
| Application file | `--app` | | `app.php` |

`kuman.php` is optional, and is needed for additional processes:

```php
<?php

return [
    'port' => 8642,
    'memory_limit' => 256,      // MB, workers above this limit are recycled
    'processes' => [
        'queue' => ['handler' => App\QueueWorker::class, 'count' => 2],
    ],
];
```

A handler is a class name that is instantiated after the fork, and may define
`onWorkerStart`, `onMessage`, `onClose` and other Workerman callbacks. Set
`listen` if the process needs to open its own port. Changes to `kuman.php`
require `kuman restart`.

## Commands

```bash
vendor/bin/kuman              # start in the foreground
vendor/bin/kuman start -d     # daemon
vendor/bin/kuman reload       # reload code
vendor/bin/kuman stop
vendor/bin/kuman status
vendor/bin/kuman connections
```

## Things to keep in mind

Kuman runs your code in a process that stays alive, unlike PHP-FPM.

- **Blocking code holds the worker.** A PDO query or `sleep()` means one worker
  serves one request at a time, so `WORKERS` is your main capacity.
- **Fibers only help async code.** `--loop=fiber` needs `revolt/event-loop` and
  makes `Timer::sleep()` non-blocking, but it does not change plain PDO or curl.
- **State is not shared between workers.** Broadcasting WebSocket messages to all
  workers needs pub/sub such as Redis.
- **Reloading drops long-lived connections.** WebSocket and SSE clients must
  reconnect.
- **Install `ext-event` in production.** Without it the `select()` loop is limited
  to 1024 connections.
- **`exit()` and `die()` end the worker,** not just the request.

## Tests

```bash
composer test
```

The tests start a real server on port 18642, on both the default and fiber loops.

## License

MIT. Some code is adapted from [webman](https://github.com/walkor/webman).
