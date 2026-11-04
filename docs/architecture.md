# Architecture

How Kuman is put together, for anyone changing the framework itself. To use Kuman, read
the [README](../README.md) and [Requests and responses](request-response.md) instead.

- [Overview](#overview)
- [Source layout](#source-layout)
- [Process model](#process-model)
- [Boot sequence](#boot-sequence)
- [Request lifecycle](#request-lifecycle)
- [Routing](#routing)
- [Middleware](#middleware)
- [Handlers](#handlers)
- [Injection and validation](#injection-and-validation)
- [OpenAPI](#openapi)
- [HTTP messages](#http-messages)
- [Authentication](#authentication)
- [Long-lived connections](#long-lived-connections)
- [Timers and errors](#timers-and-errors)
- [Reloading and memory](#reloading-and-memory)
- [Invariants](#invariants)
- [Tests](#tests)
- [Deployment](#deployment)

## Overview

Kuman is a thin layer over [Workerman](https://github.com/walkor/workerman). Workerman
owns the sockets, the event loop, the HTTP parser and the worker processes. Kuman adds
Express-style routing, middleware, PSR-7 messages, WebSocket and SSE on the HTTP port,
parameter injection with validation, and an OpenAPI document of the routes.

| Dependency | Used for |
|---|---|
| `workerman/workerman` | Processes, event loop, HTTP and WebSocket protocols |
| `workerman/coroutine` | `Context`, the per-fiber store behind `Request::current()` |
| `nikic/fast-route` | Route matching |
| `psr/http-message` | The interfaces `Request`, `Response`, `Uri`, `Stream` and `UploadedFile` implement |
| `revolt/event-loop` | Optional, needed only for `--loop=fiber` |

The application is one PHP file, `app.php` by default, that returns a closure taking
`Kuman\App`. Optional server settings live in `kuman.php`.

## Source layout

Everything is in `src/`, in the `Kuman` namespace.

| File | Responsibility |
|---|---|
| `Server.php` | Master side: command line, configuration, creating the workers |
| `App.php` | Worker side: route and middleware definitions, the request kernel, static files, error rendering, timers |
| `Route.php` | One route as defined: methods, path, middleware, handler, and the steps found in the handler |
| `Router.php` | Express-style paths on FastRoute, with a bounded match cache |
| `Prefixed.php` | Middleware limited to a path prefix, from `use('/prefix', ...)` |
| `Resolver.php` | Plans what a handler's parameters need when the worker starts, and supplies it per request |
| `Abort.php` | Exception that answers with a response, without being logged |
| `Validation/` | `Dto`, `Field` (reading and converting one value), `Hydrator` (filling a Dto), `Rule`, `Invalid`, and the rule attributes |
| `Attributes/` | Parameter sources (`Query`, `Body`, `Header`, `Cookie`), `Depends`, and the documentation attributes |
| `OpenApi.php` | Builds the OpenAPI 3.1 document from the routes, and the docs pages |
| `Request.php` | Workerman's request extended with PSR-7 `ServerRequestInterface` |
| `Response.php` | Standalone immutable PSR-7 response, converted to Workerman's when sent |
| `Message.php` | Trait with the PSR-7 header methods shared by `Request` and `Response` |
| `Uri.php`, `Stream.php`, `UploadedFile.php` | PSR-7 value objects |
| `WebSocket.php` | Protocol wrapper that lets WebSocket share the HTTP port |
| `Sse.php` | One open event stream and its timers |
| `Monitor.php` | File watcher and memory limit |
| `Auth.php` | Factories for the guards, session login and logout, password and token helpers |
| `Auth/Guard.php` | Base class of the guards: the middleware itself, `optional()`, `onDeny()` |
| `Auth/BasicGuard.php`, `BearerGuard.php`, `ApiKeyGuard.php`, `SessionGuard.php`, `AnyGuard.php` | One way of proving an identity each |
| `Auth/Jwt.php` | Issues and verifies JSON Web Tokens, with no dependency beyond `ext-openssl` for RS and ES |

`bin/kuman` finds the Composer autoloader and calls `Kuman\Server::run(getcwd())`, so the
working directory is the project root. `runtime/`, `app.php`, `kuman.php` and `public/`
are all resolved from it.

Documentation lives in `docs/`, with the Indonesian translations in `docs/id/` under the
same file names. `docs/id/README.md` is the translation of the root `README.md`.

## Process model

```
master                    never loads app.php; parses commands, forks and supervises
├── kuman worker × N      the HTTP port; N is --workers, by default the CPU count
├── monitor × 1           file watcher and memory limit; not reloadable
└── custom process × n    one group per entry in 'processes' of kuman.php
```

Each worker is single-threaded and runs its own copy of the application. Nothing is
shared between workers: a closure variable in `app.php` is per worker, and so are the
router, the match cache and open connections.

The event loop is chosen by `--loop`: `event`, `select`, `fiber`, or Workerman's automatic
choice when empty. The HTTP kernel is the same on all of them, but only the fiber loop
lets one worker suspend a handler and serve another request meanwhile. That is why the
kernel must not keep per-request state outside the request and `Context`.

## Boot sequence

`Server::run` in the master:

1. Resolves the command. No command means `start`, and an unknown one prints the usage.
2. Merges configuration, lowest precedence first: defaults, `kuman.php`, environment,
   flags.
3. For `start` and `restart`, runs `php -l` on the application file in a subprocess. It
   must not be `require`d here (see [Invariants](#invariants)), and a syntax error would
   otherwise only appear as a worker restart loop.
4. Points Workerman's pid, log, status and stdout files at `runtime/`.
5. Calls `Http::requestClass(Request::class)`, so Workerman decodes into `Kuman\Request`.
6. Creates the HTTP worker, the custom processes and the monitor, then calls
   `Worker::runAll()`, which also handles `stop`, `reload`, `status` and the rest.

`onWorkerStart`, in each forked worker:

1. `Server::sessions` sets the session defaults, before `app.php` so it can override them.
2. `require`s the application file and creates `new App($publicPath, $debug)`.
3. Calls the closure, which defines routes and middleware. A handler that names a missing
   class or method throws here, so a mistake stops the worker at start.
4. `App::start` builds the pipeline of each route, which plans its handler (see
   [Injection and validation](#injection-and-validation)); a parameter nobody supplies
   throws here. It then compiles the router, builds the kernel, installs `onMessage` and,
   on worker 0 only, starts the `every()` timers.

Routes are collected as `Route` objects first and turned into pipelines only in `start()`,
so `provide()` and `scoped()` may come after the routes that use them.

Custom processes follow the same rule: `Server::process` instantiates the handler class
after the fork and copies its Workerman callbacks onto the worker.

## Request lifecycle

```
TcpConnection                    bytes in
└─ Workerman Http protocol       frames and decodes to Kuman\Request, or reuses a cached one
   └─ App::onMessage             clones the request, opens Context
      ├─ kernel                  global middleware, then App::dispatch
      │  └─ App::dispatch        400 for unsafe paths, then Router::match
      │     ├─ route found       sets $request->params, runs the route pipeline:
      │     │                    group middleware, route middleware, answering handler,
      │     │                    which Resolver::handle calls when it was planned
      │     └─ no route          public/ file, then 405, then notFound(), then 404
      ├─ Response::from          normalizes the result; a Throwable goes to App::render
      └─ Request::respond        toWorkerman(), then connection send() or close()
```

- **Normalizing.** `Response::from` turns a string into HTML, a PSR-7 or Workerman
  response into a `Kuman\Response`, and anything else into JSON.
- **`null` is a signal.** It means the handler kept the connection, as WebSocket, SSE and
  deferred responses do, so `onMessage` sends nothing.
- **Sending.** `respond` strips the body for `HEAD`. It then keeps the connection when the
  client allows it or when the response is chunked, and closes it otherwise.
- **Static files.** `App::staticFile` is reached only for `GET` and `HEAD` with no matching
  route. It refuses dotfiles other than `.well-known/`, PHP sources, and any path that
  `realpath` resolves outside `public/`. They pass through the kernel, so global middleware
  applies to them; route and group middleware does not.

## Routing

`Router` translates `/users/:id`, `/users/:id(\d+)` and `/files/*` into FastRoute patterns.
`{name}` passes through unchanged, because it is already FastRoute syntax. Routes are
collected during definition and compiled once in `App::start`.

`match` strips a trailing slash and consults a cache keyed by method and path. Paths come
from clients, so the cache holds 1024 entries and stores only successful matches. Route
parameters are URL-decoded after matching.

Static routes must come before dynamic ones that would shadow them. FastRoute raises this
at compile time, which fails the worker at start.

## Middleware

A middleware is `fn(Request $req, callable $next)`. `App::pipe` builds the chain with
`array_reduce`, and wraps each layer so `$next` always returns a `Kuman\Response` or
`null`.

There are two chains, which is what decides whether a middleware sees 404s and static
files:

| Defined with | Chain | Runs for |
|---|---|---|
| `use($mw)` | kernel | Every request, including 404s and static files |
| `use('/prefix', $mw)` | kernel | Every request under the prefix, checked at run time by `App::under` |
| `group('/x', $mw, ...)` | route | Routes of that group |
| `use(...)` inside a group | route | Routes defined after it in that group |
| `get('/x', $mw, $handler)` | route | That route |

The route chain is built when the route is defined, from `$scope` plus the route's own
middleware, so definition order matters inside a group. `App::group` saves and restores
`$prefix` and `$scope`. `App::load` requires route files on the same `App`, so a file
loaded inside a group inherits both. It resolves relative paths against the calling
file, read from `debug_backtrace`, and requires each file in a static closure so the
file sees `$app` but not `$this`.

`use('/prefix', $mw)` wraps the middleware in a `Prefixed` object rather than a closure,
so `OpenApi` can still find a guard inside it. `Prefixed` compares against the decoded
path with repeated slashes collapsed, because static files are looked up by decoded path. A guard must see the same path the file lookup
will use, or `/%70rivate/note.txt` would bypass `use('/private', $guard)`.

## Handlers

`App::resolve` accepts a callable, an invokable class name or `[Controller::class,
'method']`, and validates it when the route is defined. Controllers named by class get a
new instance per request, so no state survives between requests; an instance passed in
keeps its state.

`App::answer` handles the last handler of a route. It asks `Resolver::plan` first. A
handler whose parameters only fit the plain shapes gets no plan, and reflection decides
the call shape as before: more than one parameter, or a variadic one, means Slim style,
`($request, $response, array $args)`; anything else receives only the request.

## Injection and validation

`Resolver::plan` reads the parameters of a handler once, at `App::start`, and turns each
into a **step**: request, response, route params, a provided service, a default, one
field, a Dto, or a dependency with a plan of its own. A handler is planned when at least
one step is something the plain shapes do not give. A controller whose constructor needs
injecting is planned too, with the plain shape for its method if that is all it needs.

The rule that keeps old handlers working: an untyped parameter is never read by name. In
the Slim shape `fn($req, $res, $id)`, `$id` is a position and gets the route params, even
on `/users/:id`. A planned handler with an untyped parameter fails at start.

`Resolver::handle` runs a plan. The values a request produces, the dependency results and
the decoded body, live in `$memo` and `$errors`, local variables of that call. The plan,
the `Field` objects and the rule instances are shared by every request of the worker and
hold descriptions only. That is what makes it safe on the fiber loop, with nothing to
reset between requests.

Errors from every step are collected before anything is called, so a client sees all its
mistakes at once, and a dependency missing a value is not called. `Invalid` then carries
them out. `App::answer` catches `Abort`, which `Invalid` extends, inside the route
pipeline and returns its response, so middleware sees a 422 like any other answer. An
`Abort` thrown anywhere else reaches `App::render`, which answers with it without
logging.

`Field` is the only place a value is converted. JSON keeps its types, so it is checked
strictly; form, query, header and path values are strings and are converted. A value is
checked before it is assigned, because a `TypeError` on a typed property would be a 500
caused by the client. `Hydrator` creates a Dto without its constructor and sets each
property through reflection, which also initialises `readonly` properties.

The body is read once per request through `getParsedBody()`, so middleware that calls
`withParsedBody()` is respected. Workerman casts a failed `json_decode()` to `[]` and a
JSON scalar to a one-item list, so `Resolver::body` looks again at an empty result and
refuses a list. Uploads are merged in only for multipart bodies, because
`getUploadedFiles()` is cheap otherwise.

## OpenAPI

`enableDocs()` adds two `Route` objects at the front of the list, so a static `/docs` is
never refused behind a route like `/:slug` defined earlier. They are not documented
themselves, and neither are `ws()` and `sse()` routes.

`OpenApi` builds the document from the `Route` list on the first request for it, then
the worker keeps the JSON string. Paths come from `Router::parse`, which is FastRoute's
own parser, so optional segments become separate paths and regex constraints become
patterns. Parameters, bodies and the 422 response come from the steps `App::answer`
stored on the route, through the plans of dependencies. Guards are found in the global
middleware, the route's middleware, and inside `Prefixed`; each describes itself with
`Guard::security()`.

The pages are HTML strings that load pinned scripts from jsDelivr with integrity hashes.

## HTTP messages

`Request` and `Response` implement PSR-7 in deliberately different ways.

**`Request` extends Workerman's request.** The parser, lazy parsing and upload handling
come for free. The `with*()` methods return copies through `copy()` and write into
Workerman's `$data`, so `path()`, `get()`, `header()` and `method()` agree with the copy.
The router reads `path()` and `method()`, so middleware that rewrites the URI changes the
route that matches.

**`Response` stands alone.** Workerman's `getHeader`, `getReasonPhrase` and `withBody`
have signatures that clash with the PSR-7 interface, so it cannot extend them. It stays a
plain immutable object until `toWorkerman()`, which `Request::respond` calls. Handlers
returning a response from another PSR-7 library go through the same path.

The `Message` trait holds the header logic for both. It keeps a map of lowercase name to
the name as given and its values. `Request` seeds the map from Workerman's headers, and
`headersChanged()` writes changes back so `header()` stays in step.

## Authentication

A guard is an invokable object extending `Auth\Guard`, so `App` treats it as any other
middleware and has no knowledge of authentication. `Guard::__invoke` calls `authenticate()`.
On success it passes on a copy of the request holding the user in the `user` attribute,
which `Request::user()` reads. On failure it answers through `deny()`.

`Request::current()` normally stays on the request Kuman received. A guard is the exception:
it replaces the `Context` entry with its copy, or `Request::current()->user()` would be
`null` for code running behind it.

One guard object serves every request of a worker, on the fiber loop concurrently, so
guards hold configuration only. `optional()` and `onDeny()` return copies for the same
reason: a shared guard must not change behind another route. Mutating one would silently
open the routes it protects.

`Auth::jwt()` has no class of its own. It is a `BearerGuard` whose callback calls
`Jwt::verify()`. `ApiKeyGuard` is a `BearerGuard` reading another place.

Sessions are Workerman's, reached through `Request::session()`, with `Server::sessions`
replacing its defaults. The session cookie goes through `$connection->headers`, which
`Http::encode` merges with the response's own `Set-Cookie` headers. Kuman itself never
writes that property, because Workerman marks it internal: `Auth::logout()` removes the
cookie with `withCookie()` on the response it is given.

## Long-lived connections

**WebSocket.** `App::ws` is a `GET` route, so route middleware guards the upgrade. The
handler swaps `$connection->protocol` to `Kuman\WebSocket`, installs the callbacks, and
replays the raw request through `WebSocket::input` to perform the handshake. It returns
`null`, and from then on the connection speaks WebSocket.

**SSE.** `App::sse` is also a `GET` route. It sends the headers directly, with no
`Content-Length`, then hands the handler a `Sse` object. `Sse` owns its timers and stops
them from the connection's `onClose`.

**Deferred responses.** A handler returns `null` and calls `$req->respond()` later.

## Timers and errors

`every()` runs on worker 0 only, so one tick runs once however many workers there are.
`after()` runs in the worker that called it.

An uncaught exception outside a request would end the worker, so each entry point has its
own boundary:

| Entry point | Boundary | After a failure |
|---|---|---|
| HTTP request | `try`/`catch` in `App::onMessage`, then `App::render` | Logged; `onError()` or a 500 answers |
| `onError()` handler itself | `App::render` | Logged; plain 500 |
| `every()`, `after()`, `Sse::every()`, `Sse::onClose()` | `App::guard` | Logged; the worker continues |
| WebSocket callbacks | `App::socketCallback` | Logged; connection closed |
| SSE handler | `try`/`catch` in `App::sse` | Logged; stream closed |

`App::report` writes to `runtime/kuman.log` through `Worker::log`. Exception details reach
the client only with `--debug`. A direct `Timer::add()` in application code has no boundary
unless it is wrapped in `$app->guard()`.

## Reloading and memory

`Monitor` runs in its own non-reloadable process, and always exists because it also
enforces the memory limit.

**Watching** is on by default in the foreground and off with `-d`; `KUMAN_WATCH` overrides
both. Every second it scans the project, skipping `.git`, `node_modules`, `public`,
`runtime`, `tests` and `vendor`, for changed `.php`, `.html` and `.env` files. A changed
PHP file is linted first, so a half-saved file does not take workers down. Then it sends
`SIGUSR1` to the master, which restarts the workers. Only files loaded after the fork are
picked up this way. For files the master had already loaded, such as `kuman.php`, the
monitor prints a message asking for a restart.

`Server::invalidateOpcache` runs on master reload, so a new worker does not run stale
bytecode.

**The memory limit** is checked every 60 seconds. Children of the master whose resident
size exceeds the limit receive `SIGINT`, and the master replaces them. The limit is
`memory_limit` from `kuman.php`, otherwise 80% of PHP's `memory_limit` with a floor of 50
MB; zero disables it. It reads `/proc`, so it only works on Linux.

## Invariants

These do not show in the API and can regress without an obvious symptom. Keep the guard
when changing the code around it.

| Constraint | Why | Guard | Test |
|---|---|---|---|
| The master never loads `app.php` | Workers inherit what the master loaded, so a reload could not refresh it | `Server::run` loads it in `onWorkerStart`; `check()` lints in a subprocess | `reload picks up app.php` |
| Clone the request per handler call | `TcpConnection` reuses a cached request object for identical bytes. On the fiber loop a suspended handler would see it pointed at another connection | `clone` in `App::onMessage` | `round 2 own connection` |
| WebSocket messages are objects | `TcpConnection` has one decode cache per process, across protocols. A cached string served to an HTTP connection crashes the worker | `WebSocket::decode` wraps the message in a `stdClass` | `replayed frame keeps the worker alive` |
| The message callback checks for `stdClass` | The cache also works the other way: request bytes sent as a frame reach the callback undecoded | `instanceof` check in `App::ws` | `http bytes on a websocket close it` |
| Copies do not delete uploads | Workerman's `__destruct` unlinks temporary files, so a dropped `with*()` copy would delete them under the request still in use | `derived` flag, set in `copy()` and reset by `withBody()` | `a dropped copy keeps the upload` |
| `withParsedBody()` does not sync `post()` | Workerman's `file()` calls `parsePost()` when it finds no files, which overwrites `data['post']` | `getUploadedFiles()` calls `file()` only for multipart | `no uploads on a form` |
| Only chunked responses hold the connection | Workerman tracks files being streamed, so `close()` still delivers them. Chunks sent later need the connection to stay open | `$chunked` in `Request::respond` | `chunked survives Connection: close`, `large file over Connection: close` |
| The router cache is bounded | Paths come from clients | `Router::CACHE_SIZE` | None |
| `optional()` and `onDeny()` copy the guard | A guard is shared between routes, so mutating it would open the others | `clone` in `Guard` | `optional() leaves the shared guard strict` |
| A guard keeps `Request::current()` in step | Code behind it would otherwise read a `null` user | `Context::set` in `Guard::__invoke` | `basic password with a colon` |
| Reading a login starts no session | `session()` would create one and send a cookie to every anonymous visitor | `isValidSessionId` check in `SessionGuard` | `session guard starts no session` |
| A login gets a new session id | An id planted before the login must stay worthless | `sessionRegenerateId(true)` in `Auth::login` | `login replaces a planted session id` |
| A login is saved at once | Sessions are written at destruct, after the response left. The client's next request could reach another worker first | `save()` in `Auth::login` | `login is stored before the response` |
| The token's header cannot pick the algorithm | It would choose `none`, or HS256 with the public key as the secret | `alg` comparison in `Jwt::verify` | `jwt header names another algorithm` |
| `runtime/sessions` is mode 0700 | File names are session ids | `mkdir` in `Server::run` | `session directory is private` |
| Per-request values stay in locals of `Resolver::handle` | On the fiber loop, state kept on the shared resolver would leak between suspended requests | `$memo` local in `handle()` | `scoped made again for the next request`, `suspended dependency keeps its request` |
| `Abort` is returned inside the pipeline | Thrown past the middleware, a 422 would lose CORS and other headers | `catch (Abort)` in `App::answer` | `middleware sees a 422` |
| A Dto on POST reads the body only | `all()` merges the query, so a link could set fields the form did not send | `'body'` in `Resolver::input` | `query cannot fill a body` |
| Malformed JSON is a 400 | Workerman decodes it to `[]`, which would look like an empty body | `json_last_error()` in `Resolver::body` | `malformed json` |
| A JSON list is refused | Workerman turns `"text"` into `["text"]` | `array_is_list()` in `Resolver::body` | `json list` |
| Values are checked before assignment | A `TypeError` on a typed property is a 500 caused by the client | `Field::cast` | `form int`, `infinite float` |
| An untyped parameter is never read by name | `fn($req, $res, $id)` must keep receiving the route params | `$type !== null` in `Resolver::step` | `untyped parameter stays a position` |
| `#[Required]` refuses empty values | Otherwise it would only change the message | `$explicit` in `Field::read` | `custom messages` |
| The docs routes come first | FastRoute refuses a static route behind a variable one | `array_unshift` in `App::enableDocs` | `docs behind a catch-all` |
| Prefix middleware is an object | A guard wrapped in a closure would leave its routes documented as public | `Prefixed` | `guard on a prefix` |

The cache in question is the `$requests` array in `TcpConnection::baseRead`. It is keyed
by the raw buffer, takes buffers up to 2048 bytes, and holds at most 512 entries.

## Tests

`composer test` runs `tests/run.php`, a single script with no test framework. `check()`
compares with `===`, and the helpers speak raw HTTP and WebSocket over a socket.

- Object tests (`psr7Objects`, `controllerErrors`, `authObjects`, `injectionObjects`) run
  in the test process. `injectionObjects` calls `App::start` with a bare `Worker` to check
  that mistakes stop a worker at start.
- Integration suites copy `tests/fixture/` to `tests/tmp/` and boot a real server through
  `bin/kuman` on port 18642. The common suite runs on the default loop and again on the
  fiber loop, which adds the `concurrency` and `injectionConcurrency` checks.
- `watching` and `configuration` run on their own, since they start and stop the server
  themselves.

For a behaviour guard, confirm the test fails with the guard removed. A passing test does
not show that it covers the guard.

## Deployment

`Dockerfile` builds a PHP 8.3 CLI image on Alpine with `pcntl`, `pdo`, `pdo_mysql` and
opcache. `docker-compose.yml` mounts the project at `/app`, publishes port 8642, runs
`php bin/kuman start` in the foreground and sets `KUMAN_WATCH=0`.

`ext-event` is optional, but without it the `select()` loop is limited to 1024
connections.
