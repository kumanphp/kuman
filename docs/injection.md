# Injection

A handler declares what it needs as typed parameters, and Kuman passes it in. Kuman works
out where each parameter comes from once, when the worker starts. A parameter it cannot
supply stops the worker there, with its name in the message, instead of failing a request
later.

- [What a handler can ask for](#what-a-handler-can-ask-for)
- [Handlers that already work](#handlers-that-already-work)
- [Services for the whole worker](#services-for-the-whole-worker)
- [Values made per request](#values-made-per-request)
- [Depends](#depends)
- [Controllers](#controllers)
- [Lifetimes](#lifetimes)
- [Things that trip people up](#things-that-trip-people-up)

## What a handler can ask for

| Parameter | Receives |
|---|---|
| `Request $req`, or `ServerRequestInterface` | the request, holding the user a guard let through |
| `Response $res`, or `ResponseInterface` | an empty response to fill |
| `array $args` | the route parameters, as in Slim |
| A `Dto` class | the validated input, see [Validation](validation.md) |
| `int $id` named like a route parameter | that parameter, converted |
| `#[Query]`, `#[Header]`, `#[Cookie]`, `#[Body]` | one value from that part of the request |
| A class given to `provide()` | that object |
| A class given to `scoped()` | the object made for this request |
| `#[Depends(...)]` | the result of another function |
| Anything with a default | the default, when nothing above applies |

```php
$app->post('/teams/:team/members', function (
    Request $req,
    int $team,
    AddMember $input,
    Mailer $mailer,
) {
    $mailer->invite($input->email, $team, $req->user());
    return Response::json(['invited' => $input->email], 201);
});
```

## Handlers that already work

Handlers written for the plain shapes keep them. Kuman only injects when a parameter asks
for something those shapes do not give: a Dto, a typed route parameter, an attribute, a
service.

```php
fn() => ...;                                   // as before
fn(Request $req) => ...;                       // as before
fn($request, $response, array $args) => ...;   // as before, Slim style
fn($req, $res, $id) => ...;                    // as before: untyped means a position
```

An untyped parameter is never read by name. In a handler that is injected, an untyped
parameter stops the worker at start, because Kuman cannot tell what it is.

## Services for the whole worker

`provide()` registers an object that handlers ask for by class. Create it in the closure
of `app.php`, which runs in each worker after the fork:

```php
return function (App $app) {
    $app->provide(PDO::class, new PDO($dsn, $user, $password));
    $app->provide(Mailer::class, new Mailer(getenv('SMTP_URL')));

    $app->get('/users/:id', fn(int $id, PDO $db) => findUser($db, $id));
};
```

One object serves every request of the worker, so it must not hold anything about a
request. A database connection is fine; a "current user" property is not. `provide()`
can come after the routes that use it.

## Values made per request

`scoped()` registers a function that makes an object when a request asks for it. Its own
parameters are injected the same way. It runs at most once per request, however many
parameters and dependencies ask for the class:

```php
$app->scoped(Tenant::class, function (#[Header('x-tenant')] string $slug, PDO $db) {
    return findTenant($db, $slug) ?? throw new Abort(Response::json(['error' => 'unknown tenant'], 404));
});

$app->get('/orders', fn(Tenant $tenant, PDO $db) => ordersOf($db, $tenant));
```

When the request is answered, the object is dropped with it. Nothing has to be reset.

## Depends

`#[Depends]` names the function on the parameter instead of on the class, for a value
whose type says little, such as a string or an int. Attributes cannot hold closures, so it
takes an invokable class or `[SomeClass::class, 'method']`:

```php
use Kuman\Attributes\Depends;

final class CurrentLocale
{
    public function __invoke(#[Header('accept-language')] string $language = 'en'): string
    {
        return substr($language, 0, 2);
    }
}

$app->get('/greeting', fn(#[Depends(CurrentLocale::class)] string $locale) => greet($locale));
```

A dependency can stop the request by throwing `Kuman\Abort`, see
[Validation](validation.md#stopping-a-request-yourself). When a dependency needs a value
that is missing, its error is reported together with the handler's, and it is not called.

A dependency that depends on itself, through others, stops the worker at start.

## Controllers

A controller named by class gets its constructor parameters injected too, and its method
parameters like any handler:

```php
class OrderController
{
    public function __construct(private PDO $db)
    {
    }

    public function show(int $id): array
    {
        return findOrder($this->db, $id);
    }
}

$app->provide(PDO::class, $db);
$app->get('/orders/:id', [OrderController::class, 'show']);
```

The controller is still created for each request. A controller passed as an instance,
`[$controller, 'show']`, is used as given. Route middleware written as a controller method
is not injected: it keeps `($request, $next)` and a constructor without arguments.

## Lifetimes

| Registered with | Made | Lives |
|---|---|---|
| `provide()` | once, by you, in `app.php` | as long as the worker |
| `scoped()`, `#[Depends]` | when a request first asks | that request |
| Dto, parameters | for each request | that request |

On the fiber loop, requests suspended in the same worker each keep their own values.

## Things that trip people up

- **Guards are middleware, not dependencies.** Use them to refuse requests, and read the
  user with `$req->user()`. A dependency runs after every middleware.
- **A blocking call still blocks.** A `PDO` from `provide()` serves one query at a time per
  worker, as before.
- **A `scoped()` function that is never asked for never runs.** Do not rely on it for side
  effects such as logging.
- **Static routes still go first.** Injection does not change the routing rules.
