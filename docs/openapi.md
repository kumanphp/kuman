# API documentation

`enableDocs()` serves an OpenAPI 3.1 document of your routes and a page to browse and try
them. The document is read from the routes themselves: paths, methods, parameters, Dto
schemas, guards and validation errors. There is nothing to write by hand, and attributes
add what the code cannot say.

- [Turning it on](#turning-it-on)
- [What is documented](#what-is-documented)
- [Adding descriptions](#adding-descriptions)
- [Responses](#responses)
- [Authentication](#authentication)
- [What is left out](#what-is-left-out)
- [Things that trip people up](#things-that-trip-people-up)

## Turning it on

```php
$app->enableDocs(
    path: '/docs',
    title: 'Orders API',
    version: '1.0.0',
    ui: 'scalar',            // 'scalar', 'swagger', 'redoc', or null for the document only
    spec: '/openapi.json',
    middleware: [$guard],    // see below
);
```

Then open <http://localhost:8642/docs>. The document is built on the first request for it
in each worker, so it costs nothing until someone reads it.

Docs are off unless you call `enableDocs()`, because the document lists every route,
including the ones you did not link to. Outside development, put a guard in `middleware`
or call it only when `getenv('APP_ENV') === 'local'`.

The pages load their scripts from jsDelivr, pinned to one version and checked with
Subresource Integrity, so the browser refuses a changed file. For a network without
internet access, use `ui: null` and serve a viewer of your own from `public/`.

Inside a group, the page and the document get the group's prefix and middleware. Calling
`enableDocs()` after a catch-all route such as `/:slug` works: the docs are registered
first.

## What is documented

| From | Becomes |
|---|---|
| `/users/:id(\d+)` | `/users/{id}`, a path parameter with that pattern |
| `/a[/{b}]` | two paths, `/a` and `/a/{b}` |
| `/files/*` | `/files/{wildcard}`, explained, since OpenAPI parameters hold no slash |
| `all()` | GET, POST, PUT, PATCH and DELETE |
| `int $id` | an integer path parameter |
| A Dto on POST, PUT, PATCH, DELETE | a JSON request body, multipart when it has an upload |
| A Dto on GET | one query parameter per field |
| `#[Query]`, `#[Header]`, `#[Cookie]`, `#[Body]` | that parameter |
| Rules | `format`, `minLength`, `minimum`, `pattern` and the rest |
| Defaults, enums, nullable types | `default`, `enum`, `"type": ["string", "null"]` |
| Parameters of `scoped()` and `#[Depends]` functions | parameters of every route that uses them |
| A handler that validates | a 422 response with the error schema |
| A guard | `security`, and a 401 response |

## Adding descriptions

```php
use Kuman\Attributes\{Description, Summary, Tag};

#[Tag('orders')]
class OrderController
{
    #[Summary('Show one order')]
    #[Description('Includes the items and the delivery address.')]
    public function show(int $id): OrderView { ... }
}

$app->get('/health', #[Summary('Health check')] fn() => ['ok' => true]);
```

`#[Description]` also works on a Dto property and on a handler parameter. `#[Tag]` on a
controller class applies to all its methods.

## Responses

Without attributes, an operation has a 200 response. A return type naming a class
describes its body:

```php
public function show(int $id): OrderView    // 200 with the schema of OrderView
```

`#[Returns]` describes responses explicitly. It is not called `Response`, so it does not
clash with `Kuman\Response` in the same file:

```php
use Kuman\Attributes\Returns;

#[Returns(201, OrderView::class, 'Created')]
#[Returns(200, [OrderView::class])]          // a list
#[Returns(404, description: 'No such order')]
#[Returns(200, 'string')]                     // HTML
```

A response class need not be a Dto: its public properties are described. A class with a
type the document cannot describe, such as `DateTimeImmutable`, appears as a plain object.

## Authentication

Guards are found wherever they are attached: on the route, on a group, or with
`use('/prefix', $guard)`.

| Guard | Security scheme |
|---|---|
| `Auth::basic()` | `basicAuth`, HTTP basic |
| `Auth::bearer()`, `Auth::jwt()` | `bearerAuth`, HTTP bearer |
| `Auth::apiKey()` | `apiKey.X-API-Key`, an API key in that header |
| `Auth::session()` | `sessionCookie`, the session cookie |
| `Auth::any(...)` | any of its guards |
| `->optional()` | the guard, or none |

Two guards on one route are both required. A guard class of your own is documented when
it overrides `security()`:

```php
public function security(): array
{
    return ['partnerToken' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Partner']];
}
```

## What is left out

- WebSocket and SSE routes, which OpenAPI cannot describe.
- The docs page and the document themselves.
- Routes marked `#[Hidden]`, on a handler or a controller class. Hidden from the document
  only: the route still answers.
- What a plain handler reads with `$req->get()` or `$req->post()`. Ask for a Dto or a typed
  parameter to have it documented.

## Things that trip people up

- **The document follows what the code checks.** An `onInvalid()` that changes the error
  format makes the 422 schema wrong; describe yours with `#[Returns(422, ...)]`.
- **Global middleware that is not a guard is invisible.** A closure that refuses requests
  does not appear under `security`.
- **The document is cached per worker.** Reloading after a change rebuilds it.
