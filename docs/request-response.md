# Requests and responses

A reference for `Kuman\Request` and `Kuman\Response`, followed by a tutorial that
builds a small API with them. Every example here was run against Kuman.

- [What a handler receives and returns](#what-a-handler-receives-and-returns)
- [Request reference](#request-reference)
- [Response reference](#response-reference)
- [Uploaded files, streams and URIs](#uploaded-files-streams-and-uris)
- [Tutorial: a notes API](#tutorial-a-notes-api)
- [Things that trip people up](#things-that-trip-people-up)

Kuman implements [PSR-7](https://www.php-fig.org/psr/psr-7/) for both classes. You
can use the short Kuman accessors (`$req->get('q')`), the PSR-7 methods
(`$req->getQueryParams()['q']`), or mix them. The tables below show both.

## What a handler receives and returns

A handler receives the request, and **returns** what to send:

```php
fn(Request $req) => 'text';                  // 200, text/html
fn(Request $req) => ['ok' => true];          // 200, application/json
fn(Request $req) => Response::json($d, 201); // exactly what you build
fn(Request $req) => null;                    // the handler keeps the connection
```

| Returned value | Result |
|---|---|
| `string` or `Stringable` | HTML, status 200 |
| array, object, number, bool | JSON, status 200 |
| `Kuman\Response` | sent as it is |
| Any PSR-7 `ResponseInterface` | converted, the body is read when it is sent |
| `null` | nothing is sent now, see [Request::respond()](#kuman-extras) |

A handler that declares a **second parameter** also receives an empty `Response` to
fill and the route parameters, as in Slim:

```php
$app->get('/hello/{name}', function ($request, $response, array $args) {
    $response->getBody()->write("Hello, {$args['name']}");
    return $response->withHeader('X-Greeted', 'yes');
});
```

Only the last handler of a route gets this. Route and global middleware receive
`($request, $next)`. A controller method works the same way:
`[NoteController::class, 'show']`.

## Request reference

### Reading input

| Method | Returns |
|---|---|
| `get()` | All query-string values, as an array |
| `get('q', $default)` | One query-string value |
| `post()` | All body values (form or JSON), as an array |
| `post('name', $default)` | One body value |
| `input('name', $default)` | The body value, else the query value, else the default |
| `all()` | Query and body values together, the body wins |
| `param('id', $default)` | A route parameter |
| `header('x-token', $default)` | One header, the name is case-insensitive |
| `header()` | All headers, with lowercase names |
| `cookie('session', $default)` | One cookie |
| `cookie()` | All cookies |
| `file('avatar')` | The raw upload entry, see [uploads](#uploaded-files-streams-and-uris) |

```php
// POST /search?page=2   body: {"q": "php", "page": 5}
$app->post('/search', function (Request $req) {
    return [
        'query' => $req->get(),                  // ['page' => '2']
        'body'  => $req->post(),                 // ['q' => 'php', 'page' => 5]
        'q'     => $req->input('q'),             // 'php'
        'page'  => $req->input('page'),          // 5, the body wins over the query
        'all'   => $req->all(),                  // ['page' => 5, 'q' => 'php']
        'limit' => $req->input('limit', 20),     // 20, the default
    ];
});
```

JSON bodies are parsed when the `Content-Type` contains `json`, and are read through
`post()` like a form. Query values are strings, JSON values keep their type.
Names with brackets become arrays: `?tag[]=a&tag[]=b` gives `['a', 'b']`.

### Route parameters

`/users/:id` and `/users/{id}` are the same. Parameters are URL-decoded.

```php
$app->get('/users/:id', fn(Request $req) => ['id' => $req->param('id')]);
$app->get('/digits/:id(\d+)', $handler);        // with a regex
$app->get('/files/*', fn(Request $req) => $req->param('wildcard'));   // /files/a/b.txt: 'a/b.txt'
```

`$req->params` is the whole array, and it is what a Slim-style handler gets as `$args`.

### The request line and the client

| Method | Example result for `GET /users/7?x=1` |
|---|---|
| `method()` | `'GET'` |
| `path()` | `'/users/7'`, as sent, not decoded |
| `queryString()` | `'x=1'` |
| `uri()` | `'/users/7?x=1'` |
| `host()` | `'example.com:8080'` |
| `host(true)` | `'example.com'`, without the port |
| `protocolVersion()` | `'1.1'` |
| `ip()` | The client address, `'203.0.113.9'` |
| `rawBody()` | The body exactly as received, as a string |
| `rawHead()` | The request line and headers |

`ip()` is the address of the TCP peer. Behind a proxy that is the proxy, so read
`X-Forwarded-For` yourself, and only trust it when you know the proxy sets it.

### Kuman extras

| Member | What it is |
|---|---|
| `Request::current()` | The request being handled in this fiber, for code that was not given `$req` |
| `$req->respond($value)` | Sends the response later, after the handler returned `null` |
| `$req->connection` | The Workerman connection |
| `$req->params` | The route parameters |
| `$req->user()` | Who an [authentication guard](auth.md) let through, otherwise `null` |

```php
$app->get('/slow', function (Request $req) use ($app) {
    $app->after(2, fn() => $req->respond(['done' => true]));
    return null;                    // keep the connection, answer in two seconds
});
```

### The PSR-7 view

`Request` implements `ServerRequestInterface`. Each Kuman accessor has a PSR-7 equivalent:

| Kuman | PSR-7 |
|---|---|
| `method()` | `getMethod()` |
| `uri()` | `getRequestTarget()` |
| `path()`, `queryString()` | `getUri()->getPath()`, `getUri()->getQuery()` |
| `host()` | `getUri()->getHost()`, `getHeaderLine('Host')` |
| `get()` | `getQueryParams()` |
| `post()` | `getParsedBody()`, which is `null` for an empty body or a type that is not a form or JSON |
| `header('x')` | `getHeaderLine('x')`, `getHeader('x')` (a list), `hasHeader('x')`, `getHeaders()` |
| `cookie()` | `getCookieParams()` |
| `file()` | `getUploadedFiles()` |
| `rawBody()` | `(string)getBody()`, or `getBody()->read()` |
| `ip()` | `getServerParams()['REMOTE_ADDR']` |
| `protocolVersion()` | `getProtocolVersion()` |
| `params` | `getAttributes()`, for what middleware stored |

`getUri()` builds a full URI from the `Host` header: `http://example.com:8080/users/7?x=1`
(`https` when the connection is TLS). A malformed `Host` is ignored and the URI
has only a path and query. `getServerParams()` has `REMOTE_ADDR`, `REMOTE_PORT`,
`SERVER_ADDR`, `SERVER_PORT`, `SERVER_PROTOCOL`, `REQUEST_METHOD`, `REQUEST_URI` and
`QUERY_STRING`.

### Changing a request

A request is immutable. Every `with*()` method returns a **copy** and leaves the
original alone:

| Method | Use |
|---|---|
| `withAttribute($name, $value)`, `withoutAttribute($name)` | Hand data to later middleware and the handler |
| `withHeader()`, `withAddedHeader()`, `withoutHeader()` | Change headers |
| `withMethod('PUT')` | Method override |
| `withUri($uri)`, `withRequestTarget('/x?y=1')` | Rewrite the URL: the router and `path()` follow it |
| `withQueryParams([...])`, `withCookieParams([...])` | Replace what `get()` and `cookie()` report |
| `withParsedBody($data)`, `withUploadedFiles([...])` | Replace what the PSR-7 getters report |
| `withBody($stream)` | Replace the body for both PSR-7 and `post()` |
| `withProtocolVersion('1.0')` | Rarely needed |

```php
$app->use(function (Request $req, callable $next) {
    $start = microtime(true);
    $res = $next($req->withAttribute('request-id', bin2hex(random_bytes(4))));
    return $res?->withHeader('X-Time', sprintf('%.1f ms', (microtime(true) - $start) * 1000));
});

// anywhere later: $req->getAttribute('request-id')
```

```php
// Serve /v1/... from the /api/... routes
$app->use('/v1', fn(Request $req, callable $next) => $next(
    $req->withUri($req->getUri()->withPath('/api' . substr($req->path(), 3)))
));
```

Two things to remember:

- Pass the copy on: `$next($req->withAttribute(...))`. A copy you do not pass is lost.
- `withParsedBody()` and `withUploadedFiles()` only change the PSR-7 getters.
  `post()`, `input()`, `all()` and `file()` keep reading the received body.

## Response reference

A response is immutable too. `with*()` returns a copy, so **use the return value**:

```php
$res->withStatus(404);                  // wrong: the result is thrown away
return $res->withStatus(404);           // right
```

### Creating a response

| Call | Result |
|---|---|
| `Response::json($data, $status = 200)` | JSON, `application/json` |
| `Response::html($body, $status = 200)` | `text/html; charset=utf-8` |
| `Response::text($body, $status = 200)` | `text/plain; charset=utf-8` |
| `Response::redirect($url, $status = 302)` | A `Location` header and no body |
| `Response::file($path, $req)` | A file from disk, see [files](#files) |
| `new Response($status, $headers, $body)` | Anything else |
| `Response::from($value)` | What a handler's return value goes through |

```php
Response::json(['error' => 'not found'], 404);
Response::redirect('/login', 301);
new Response(204);                                          // no content
new Response(200, ['X-Id' => '7', 'Vary' => ['A', 'B']], 'body');
```

The constructor is `new Response(int $status = 200, array $headers = [], string|StreamInterface $body = '', string $version = '1.1', string $reason = '')`.
A header value is a string or a list of strings. Without any header, a response is
sent as `text/html`.

### Status

```php
$res->withStatus(201);                  // reason phrase: Created
$res->withStatus(418, "I'm a teapot");  // custom reason phrase
$res->getStatusCode();                  // 201
$res->getReasonPhrase();                // 'Created'
```

A status outside 100 to 599 throws `InvalidArgumentException`.

### Headers

| Method | Use |
|---|---|
| `withHeader('X-A', 'v')` | Set, replacing any value |
| `withAddedHeader('X-A', 'v')` | Add another value |
| `withoutHeader('X-A')` | Remove |
| `hasHeader()`, `getHeader()` (a list), `getHeaderLine()` (joined), `getHeaders()` | Read, the name is case-insensitive |

```php
$res = Response::json($data)
    ->withHeader('Cache-Control', 'no-store')
    ->withAddedHeader('Vary', 'Accept')
    ->withAddedHeader('Vary', 'Origin');       // sent as "Vary: Accept, Origin"
```

A header name with a space, or a value with a line break, throws
`InvalidArgumentException`, which is how Kuman stops header injection. A name keeps
the case you gave it. Repeated headers are joined with a comma, except `Set-Cookie`,
which is sent as separate lines.

### Body and streams

The body is a PSR-7 stream. Write to it, replace it, or read it:

```php
$res = new Response();
$res->getBody()->write('Hello');         // write before you make copies
$res->getBody()->write(', world');
(string)$res->getBody();                  // 'Hello, world'

$res->withBody(Stream::from('other'));    // a copy with another body
```

The whole body is read into memory when the response is sent, so a stream is for
small and medium bodies. Send a large file with `Response::file()`, which streams from
disk.

`Response::json()` and the others keep the body as a plain string until something
asks for the stream, so the common path costs no stream. Write to the body
**before** you call `withStatus()` or `withHeader()`, or write to the copy you return:
a stream created later is not shared with the older copies.

### Cookies

```php
$res->withCookie('session', 'abc123');
$res->withCookie('session', 'abc123', 3600, '/', '', false, true, 'Lax');
//               name       value     maxAge path domain secure httpOnly sameSite
```

Each call adds its own `Set-Cookie` header, and the value is URL-encoded.
Read a cookie on the next request with `$req->cookie('session')`.
To delete one, send it again with `maxAge` `0`.

### Files

```php
Response::file('/srv/report.pdf', $req);      // with the request
Response::file('/srv/report.pdf');            // without it
```

The file is streamed from disk. Passing `$req` adds support for `Range` (a 206 for
resumable downloads and video seeking), `If-Modified-Since` (a 304) and `HEAD`. The
`Content-Type` comes from the extension, and unknown types are sent as downloads.
Add your own headers to the result:

```php
return Response::file($path, $req)
    ->withHeader('Content-Disposition', 'attachment; filename="report.pdf"');
```

A missing file gives a 404. You can also send part of a file with
`withFile($path, $offset, $length)`, and `getBody()` of a file response gives you
a stream of the same bytes.

### Any PSR-7 response

A handler can return a response made by another PSR-7 library. Kuman keeps its status,
headers and body stream.

## Uploaded files, streams and URIs

Uploads arrive as PSR-7 `UploadedFileInterface` objects, nested like the field names:

```php
$app->post('/avatar', function (Request $req) {
    $file = $req->getUploadedFiles()['avatar'] ?? null;     // <input type="file" name="avatar">
    if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
        return Response::json(['error' => 'no file'], 422);
    }
    $file->moveTo('/srv/avatars/' . bin2hex(random_bytes(8)) . '.png');
    return ['size' => $file->getSize()];
});
```

| `UploadedFile` | Returns |
|---|---|
| `getClientFilename()` | The name the browser sent: **never trust it** as a path |
| `getClientMediaType()` | The type the browser sent: also not trustworthy |
| `getSize()`, `getError()` | Size in bytes, and a `UPLOAD_ERR_*` code |
| `getStream()` | A stream over the temporary file |
| `moveTo($path)` | Moves it, once. Kuman deletes unmoved temporary files when the request ends |

`$files['photos'][0]` is how `name="photos[]"` arrives. The raw entries from
`$req->file('avatar')` have the keys `name`, `type`, `tmp_name`, `size` and `error`.

`Kuman\Stream` is a PSR-7 stream with two constructors, `Stream::from('text')` for a
string and `Stream::open($path, 'rb')` for a file, plus `read()`, `write()`, `seek()`,
`getContents()`, `getSize()` and `close()`. `Kuman\Uri` is a PSR-7 URI:
`new Uri('https://example.com/a?b=1')`, with `withPath()`, `withQuery()`, `withHost()`
and the rest. Both are immutable where PSR-7 says so, and they work with other
PSR-7 libraries.

## Tutorial: a notes API

We build an API that creates notes, attaches a file to a note, and downloads it. It
uses a controller, a group, middleware, cookies and error pages. Create a project and
`mkdir storage` next to `app.php`.

Run it with one worker, because the notes live in memory and
[state is not shared between workers](../README.md#things-to-keep-in-mind):

```bash
WORKERS=1 vendor/bin/kuman
```

### 1. A greeting

```php
<?php

use Kuman\App;
use Kuman\Request;
use Kuman\Response;

return function (App $app) {
    $app->get('/hello/{name}', function ($request, $response, array $args) {
        $response->getBody()->write('Hello, ' . htmlspecialchars($args['name']));
        return $response->withHeader('X-Greeted', 'yes');
    });
};
```

```
$ curl -i localhost:8642/hello/Ana
HTTP/1.1 200 OK
X-Greeted: yes

Hello, Ana
```

The second parameter made Kuman pass a `Response` and the route parameters.
Escape what you echo into HTML.

### 2. See what the client sent

```php
$app->get('/debug', fn(Request $req) => [
    'method' => $req->method(),
    'path'   => $req->path(),
    'query'  => $req->get(),
    'host'   => $req->host(true),
    'ip'     => $req->ip(),
    'agent'  => $req->header('user-agent'),
    'session' => $req->cookie('session'),
]);
```

```
$ curl -H 'User-Agent: tut' -b session=zz 'localhost:8642/debug?a=1&b[]=2'
{"method":"GET","path":"/debug","query":{"a":"1","b":["2"]},"host":"localhost","ip":"127.0.0.1","agent":"tut","session":"zz"}
```

### 3. A controller

Put the logic in a class, in `app.php` above the closure or in its own file. We keep
the notes in a property, so the controller is created **once** and passed as an
instance. A class name, `[NoteController::class, 'index']`, would create a new
controller for every request and forget the notes.

```php
class NoteController
{
    private array $notes = [];
    private int $nextId = 1;

    public function index(Request $req): array
    {
        $q = (string)$req->get('q', '');
        return array_values(array_filter(
            $this->notes,
            fn($note) => $q === '' || stripos($note['title'], $q) !== false
        ));
    }

    public function create(Request $req): Response
    {
        $title = trim((string)$req->input('title', ''));       // JSON or form
        if ($title === '') {
            return Response::json(['error' => 'title is required'], 422);
        }

        $note = ['id' => $this->nextId++, 'title' => $title, 'file' => null];
        $this->notes[$note['id']] = $note;
        return Response::json($note, 201)->withHeader('Location', "/api/notes/{$note['id']}");
    }

    public function show(Request $req): array|Response
    {
        return $this->notes[(int)$req->param('id')] ?? Response::json(['error' => 'not found'], 404);
    }

    public function delete(Request $req): Response
    {
        unset($this->notes[(int)$req->param('id')]);
        return new Response(204);
    }
}
```

### 4. Routes in a group, behind a key

```php
return function (App $app) {
    $notes = new NoteController();

    // ... the routes from steps 1 and 2 ...

    $app->use('/api', function (Request $req, callable $next) {
        return $req->header('x-api-key') === 'secret'
            ? $next($req)
            : Response::json(['error' => 'missing or wrong API key'], 401);
    });

    $app->group('/api/notes', function (App $api) use ($notes) {
        $api->get('/', [$notes, 'index']);
        $api->post('/', [$notes, 'create']);
        $api->get('/:id', [$notes, 'show']);
        $api->delete('/:id', [$notes, 'delete']);
    });
};
```

```
$ curl -i localhost:8642/api/notes
HTTP/1.1 401 Unauthorized
{"error":"missing or wrong API key"}

$ curl -i -H 'X-Api-Key: secret' -H 'Content-Type: application/json' \
       -d '{"title":"Buy milk"}' localhost:8642/api/notes
HTTP/1.1 201 Created
Location: /api/notes/1
{"id":1,"title":"Buy milk","file":null}

$ curl -H 'X-Api-Key: secret' -d 'title=Call mom' localhost:8642/api/notes    # a form works too
{"id":2,"title":"Call mom","file":null}

$ curl -H 'X-Api-Key: secret' 'localhost:8642/api/notes?q=milk'
[{"id":1,"title":"Buy milk","file":null}]

$ curl -i -H 'X-Api-Key: secret' -d 'title=' localhost:8642/api/notes
HTTP/1.1 422 Unprocessable Entity
{"error":"title is required"}

$ curl -i -H 'X-Api-Key: secret' -X DELETE localhost:8642/api/notes/2
HTTP/1.1 204 No Content
```

### 5. Attach and download a file

Add two methods to `NoteController`, and two routes to the group.

```php
public function attach(Request $req, Response $res, array $args): Response
{
    $id = (int)$args['id'];
    $file = $req->getUploadedFiles()['file'] ?? null;
    if (!isset($this->notes[$id])) {
        return Response::json(['error' => 'not found'], 404);
    }
    if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
        return Response::json(['error' => 'file is required'], 422);
    }

    // Never trust the client's file name: keep letters, digits, dot, dash and underscore.
    $name = $id . '-' . preg_replace('/[^\w.-]/', '_', basename($file->getClientFilename()));
    $file->moveTo(__DIR__ . "/storage/$name");
    $this->notes[$id]['file'] = $name;

    $res->getBody()->write(json_encode($this->notes[$id]));
    return $res->withStatus(201)->withHeader('Content-Type', 'application/json');
}

public function download(Request $req): Response
{
    $file = $this->notes[(int)$req->param('id')]['file'] ?? null;
    if ($file === null) {
        return Response::json(['error' => 'no file attached'], 404);
    }
    return Response::file(__DIR__ . "/storage/$file", $req)
        ->withHeader('Content-Disposition', "attachment; filename=\"$file\"");
}
```

```php
$api->post('/:id/file', [$notes, 'attach']);
$api->get('/:id/file', [$notes, 'download']);
```

```
$ curl -i -H 'X-Api-Key: secret' -F 'file=@up.txt;filename=my notes!.txt' localhost:8642/api/notes/1/file
HTTP/1.1 201 Created
Content-Type: application/json
{"id":1,"title":"Buy milk","file":"1-my_notes_.txt"}

$ curl -i -H 'X-Api-Key: secret' localhost:8642/api/notes/1/file
HTTP/1.1 200 OK
Content-Disposition: attachment; filename="1-my_notes_.txt"
Content-Type: text/plain
hello file

$ curl -i -H 'X-Api-Key: secret' -H 'Range: bytes=0-4' localhost:8642/api/notes/1/file
HTTP/1.1 206 Partial Content
hello
```

`attach` uses the Slim-style signature because it is handy to have `$args` and an empty
response. `download` returns `Response::file()`, which supports `Range` for free.

### 6. Cookies and redirects

```php
$app->get('/login', fn() => Response::redirect('/welcome')
    ->withCookie('session', 'abc123', 3600, '/', '', false, true, 'Lax'));

$app->get('/welcome', fn(Request $req) => 'Session: ' . $req->cookie('session', 'none'));
```

```
$ curl -i localhost:8642/login
HTTP/1.1 302 Found
Location: /welcome
Set-Cookie: session=abc123; Max-Age=3600; Path=/; HttpOnly; SameSite=Lax

$ curl -b session=abc123 localhost:8642/welcome
Session: abc123
```

### 7. A request id on every response

Global middleware that stores a value for later code, and decorates the response:

```php
$app->use(function (Request $req, callable $next) {
    $id = bin2hex(random_bytes(4));
    return $next($req->withAttribute('request-id', $id))?->withHeader('X-Request-Id', $id);
});

// in any handler: $req->getAttribute('request-id')
```

`$next()` returns `null` when a handler kept the connection, so the `?->`.

### 8. Error pages

```php
$app->notFound(fn(Request $req) => Response::json(['error' => 'no route', 'path' => $req->path()], 404));
$app->onError(fn(Throwable $e) => Response::json(['error' => 'server error'], 500));
```

```
$ curl localhost:8642/nothing
{"error":"no route","path":"/nothing"}
```

The exception is logged to `runtime/kuman.log` before the handler runs, and the worker keeps going.

## Things that trip people up

- **Use the result of `with*()`.** `$res->withHeader(...)` on its own line does nothing.
- **Return the response.** A handler without `return` returns `null`, and `null` means
  "I will answer later", so the client waits. This also applies to Slim-style handlers.
- **Write to a response body first.** A stream created after `withStatus()` or
  `withHeader()` is not shared with the older copy.
- **Pass the copy to `$next`.** `$next($req->withAttribute(...))`, not `$next($req)`.
- **`post()` reads every body that is not JSON as a form.** For XML, plain text or
  binary data it returns nonsense, so use `rawBody()` or `getBody()` there.
  `getParsedBody()` is `null` for those types.
- **Do not trust uploads.** The file name and type come from the client. Pick the name
  you store under yourself, and check the real type with `finfo` if it matters.
- **Header names from the client are lowercase.** `header('X-Token')` and
  `header('x-token')` both work; `getHeaders()` has the keys in lowercase.
- **A controller class name makes a new controller per request.** Pass an instance
  (`[$controller, 'method']`) when it should keep state.
