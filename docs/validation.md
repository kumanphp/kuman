# Validation

A handler can ask for a **Dto**, a class whose public properties describe the input.
Kuman fills it from the request and checks it before the handler runs. When the input is
wrong the handler never runs, and the client gets a 422 listing every problem.

- [Quick start](#quick-start)
- [Fields and types](#fields-and-types)
- [Required and optional](#required-and-optional)
- [Rules](#rules)
- [Where the data comes from](#where-the-data-comes-from)
- [Nested objects and lists](#nested-objects-and-lists)
- [Uploads](#uploads)
- [Single values](#single-values)
- [The error response](#the-error-response)
- [Stopping a request yourself](#stopping-a-request-yourself)
- [Things that trip people up](#things-that-trip-people-up)

## Quick start

```php
use Kuman\Validation\Attributes\{Email, MinLength};
use Kuman\Validation\Dto;

class CreateUser implements Dto
{
    #[Email]
    public string $email;

    #[MinLength(8)]
    public string $password;

    public int $age = 18;
}

$app->post('/users', fn(CreateUser $user) => ['email' => $user->email]);
```

```
POST /users  {"email": "nope"}

422 {"error": "Unprocessable Entity", "details": [
  {"field": "email", "rule": "email", "message": "Must be a valid email address."},
  {"field": "password", "rule": "required", "message": "This field is required."}
]}
```

The handler can mix a Dto with anything else it can ask for, in any order:
`fn(Request $req, CreateUser $user, int $id)`. See [Injection](injection.md).

## Fields and types

Every public, non-static property is a field. Private and static properties, and keys in
the request that are not fields, are ignored, so a client cannot set what you did not
declare. The constructor does not run; Kuman creates the object and sets the properties.

| Type | JSON body | Form, query string, header, path |
|---|---|---|
| `string` | a string | as sent |
| `int` | an integer | `"42"` becomes `42`, `"4.2"` and `"abc"` are refused |
| `float` | a number; an integer is accepted | a numeric string |
| `bool` | `true` or `false` | `1`, `0`, `true`, `false`, `on`, `off`, `yes`, `no`; empty is refused |
| `array` | an array or object | `items[]=a&items[]=b` |
| A backed enum | its value, of the backing type | its value; `"2"` for an int enum |
| Another `Dto` | an object | `address[city]=...` |
| `UploadedFileInterface` | | a file of a multipart body |
| `mixed` or no type | anything | anything |

JSON is checked strictly because JSON carries types: `"30"` is a string and does not fill
an `int`. Forms, query strings, headers and paths only carry text, so their values are
converted. A value of the wrong type is refused with the rule `type`; it never reaches the
property, so a client cannot cause a `TypeError`.

Union types, `object`, `DateTimeImmutable` and other classes are not supported. A Dto
using one stops the worker at start with a message naming the property, like a routing
mistake.

## Required and optional

A field is **required** when its type does not allow `null` and it has no default:

```php
public string $email;            // required
public string $role = 'user';    // optional, 'user' when absent
public ?string $nick = null;     // optional, null when absent
public ?string $bio;             // optional too: nullable, so null when absent
public readonly ?string $note;   // the same; PHP forbids a default on a readonly property
```

A `null` sent for a field that does not allow it counts as absent, so the default
applies, or the field is reported missing.

`#[Required]` makes a field required even with a default, refuses `''` and `[]` as well,
and sets the message:

```php
#[Required('Tell us your name.')]
public string $name = '';
```

## Rules

Rules are attributes in `Kuman\Validation\Attributes`. They only run on a value that
passed the type check, and the first failing rule of a field is the one reported.

| Rule | Passes when |
|---|---|
| `#[Required]` | present, not `null`, `''` or `[]` |
| `#[Email]` | a valid email address |
| `#[MinLength(8)]`, `#[MaxLength(64)]` | characters of a string, items of an array |
| `#[Min(1)]`, `#[Max(100)]` | a number in range |
| `#[Numeric]` | a string holding a number, such as a price kept as text |
| `#[Pattern('/^[a-z]+$/')]` | the regular expression matches; a broken pattern stops the worker at start |

Every rule takes a message as its last argument: `#[MinLength(8, 'Too short.')]`.

A rule of your own extends `Kuman\Validation\Rule`:

```php
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Slug extends Rule
{
    public function passes(mixed $value): bool
    {
        return preg_match('/^[a-z0-9-]+$/', $value) === 1;
    }

    protected function fallback(): string
    {
        return 'Must be a slug.';
    }
}
```

The rule's name in the error is its class name with a lowercase first letter, `slug`.
One rule object serves every request of a worker, so keep no state in it.

## Where the data comes from

| Request | A Dto is filled from |
|---|---|
| `GET`, `HEAD` | the query string |
| Any other method | the body: JSON, a form or multipart |
| With `#[Query]`, `#[Body]`, `#[Header]` or `#[Cookie]` on the parameter | that part |

```php
use Kuman\Attributes\Query;

$app->delete('/posts', fn(#[Query] PostFilter $filter) => ...);
```

The body never mixes with the query string. `$req->all()` merges them, so
`POST /users?role=admin` would set a `role` field that the form did not have.

A malformed JSON body is answered with 400. A JSON body that is a list or a single value
instead of an object is a 422.

## Nested objects and lists

A property typed with another Dto takes an object. PHP has no typed arrays, so
`#[ArrayOf]` gives the type of the items: a scalar name, a Dto or an enum.

```php
class Order implements Dto
{
    public Address $address;

    #[ArrayOf(Item::class)]
    #[MinLength(1)]
    public array $items;

    #[ArrayOf('int')]
    public array $codes = [];
}
```

Errors carry the path: `address.city`, `items.1.qty`.

## Uploads

```php
class Avatar implements Dto
{
    public UploadedFileInterface $file;
    public string $caption = '';
}

$app->post('/avatar', function (Avatar $input) {
    $input->file->moveTo('/srv/avatars/' . bin2hex(random_bytes(8)));
    return ['ok' => true];
});
```

The file is checked for being a successful upload, not for its size or type. Check
`getSize()` and `getClientMediaType()` yourself, and never use the client's file name as
a path.

## Single values

Parameters of the handler itself can be read the same way, with the same types, rules and
defaults:

```php
use Kuman\Attributes\{Cookie, Header, Query};
use Kuman\Validation\Attributes\Min;

$app->get('/users/:id', fn(
    int $id,                                    // the route parameter of the same name
    #[Query] #[Min(1)] int $page = 1,           // ?page=2
    #[Query('per_page')] int $perPage = 20,     // ?per_page=50
    #[Header] string $xTenant = 'main',         // X-Tenant
    #[Cookie('sid')] ?string $session = null,
) => ...);
```

A route parameter must be typed to be read this way. `/users/abc` for an `int $id` is a
422, not a 404; put a constraint in the path, `/users/:id(\d+)`, to get a 404.

## The error response

```json
{"error": "Unprocessable Entity", "details": [{"field": "...", "rule": "...", "message": "..."}]}
```

`rule` is stable, so clients can act on it. `type` and `required` are the rules for a
wrong type and a missing value. Messages are in English; translate them, or change the
whole answer, with `onInvalid()`:

```php
use Kuman\Validation\Invalid;

$app->onInvalid(function (Invalid $e, Request $req) {
    $messages = ['required' => 'Wajib diisi.', 'type' => 'Tipe data salah.'];
    return Response::json(['errors' => array_map(
        fn(array $error) => ['field' => $error['field'], 'message' => $messages[$error['rule']] ?? $error['message']],
        $e->details
    )], 422);
});
```

The answer is returned through the middleware, like the handler's own: CORS headers and
loggers see it.

## Stopping a request yourself

A check that needs the database, such as an email already taken, belongs in the handler.
Throw `Invalid` to answer with the same format:

```php
throw new Invalid([['field' => 'email', 'rule' => 'taken', 'message' => 'Already registered.']]);
```

`Kuman\Abort` answers with any response, and is not logged as an error:

```php
throw new Abort(Response::json(['error' => 'forbidden'], 403));
```

Thrown from a handler that asks for something to be injected, or from a dependency, the
answer passes through the middleware. From anywhere else it still answers, but the
middleware between does not see it.

## Things that trip people up

- **The constructor does not run.** Validate in rules, or after the fact in the handler.
- **`"30"` in JSON is not an int.** Send numbers as numbers, or type the field `string` and
  add `#[Numeric]`.
- **Guards run first.** A route with a guard answers 401 before validation looks at the
  body, so anonymous clients learn nothing about the fields.
- **Unknown fields are dropped silently.** That protects you from mass assignment, and also
  hides a typo in the client's field name.
- **Only the handler that answers is injected.** Route middleware keeps `($request, $next)`.
