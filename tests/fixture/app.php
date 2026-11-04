<?php

use Kuman\Abort;
use Kuman\App;
use Kuman\Attributes\Body;
use Kuman\Attributes\Cookie;
use Kuman\Attributes\Depends;
use Kuman\Attributes\Header;
use Kuman\Attributes\Hidden;
use Kuman\Attributes\Query;
use Kuman\Attributes\Summary;
use Kuman\Attributes\Tag;
use Kuman\Auth;
use Kuman\Auth\Jwt;
use Kuman\Request;
use Kuman\Response;
use Kuman\Sse;
use Kuman\Stream;
use Kuman\Validation\Attributes\Min;
use Kuman\Validation\Invalid;
use Workerman\Protocols\Http\Chunk;
use Workerman\Timer;

require_once __DIR__ . '/ForeignResponse.php';
require_once __DIR__ . '/Controllers.php';
require_once __DIR__ . '/Injection.php';

return function (App $app) {
    $ticks = 0;
    $tag = static fn(string $name) => static function (Request $req, callable $next) use ($name) {
        return $next($req)?->withHeader("X-$name", 'yes');
    };

    $app->use($tag('Global'));
    $app->use('/scoped', $tag('Scoped'));
    $app->use('/private', static fn() => Response::text('denied', 401));
    $app->every(0.05, function () use (&$ticks) { $ticks++; });
    $app->every(0.05, function () { throw new RuntimeException('timer failure'); });
    $app->notFound(static fn() => Response::text('custom 404', 404));

    $app->get('/', fn() => 'hello');
    $app->get('/version', fn() => 'v1');
    $app->get('/pid', fn() => ['pid' => posix_getpid()]);
    $app->get('/ticks', function () use (&$ticks) { return ['ticks' => $ticks]; });
    $app->get('/users/:id', fn(Request $req) => ['id' => $req->param('id')]);
    $app->get('/digits/:id(\d+)', fn(Request $req) => ['id' => $req->param('id')]);
    $app->get('/files/*', fn(Request $req) => ['path' => $req->param('wildcard')]);
    $app->get('/scoped/page', fn() => 'scoped');
    $app->post('/echo', fn(Request $req) => $req->all());
    $app->all('/any', fn(Request $req) => $req->method());
    $app->get('/current', fn(Request $req) => ['same' => Request::current() === $req]);
    $app->get('/redirect', fn() => Response::redirect('/target'));
    $app->get('/boom', function () { throw new RuntimeException('secret detail'); });
    $app->get('/guarded', static fn() => Response::text('blocked', 403), fn() => 'unreachable');

    $app->group('/api', $tag('Group'), function (App $api) use ($tag) {
        $api->get('/ping', fn() => 'pong');
        $api->group('/v1', function (App $v1) use ($tag) {
            $v1->use($tag('Nested'));
            $v1->get('/ping', fn() => 'pong v1');
        });
    });
    $app->get('/outside', fn() => 'outside');

    $app->load('routes/*.php');
    $app->group('/loaded/admin', $tag('Group'), fn(App $admin) => $admin->load('routes/admin/panel.php'));

    $app->get('/controller/users/mine', [Fixture\UserController::class, 'mine']);
    $app->get('/controller/users/:id', [Fixture\UserController::class, 'show']);
    $app->post('/controller/users', [Fixture\UserController::class, 'create']);
    $app->get('/controller/health', Fixture\HealthController::class);
    $app->get('/controller/instance/:id', [new Fixture\UserController(), 'show']);
    $app->get('/controller/gated/:id', [Fixture\UserController::class, 'gate'], [Fixture\UserController::class, 'show']);
    $app->group('/controller/admin', $tag('Group'), function (App $admin) {
        $admin->get('/users/:id', [Fixture\UserController::class, 'show']);
    });

    // Slim-style handlers: ($request, $response, array $args)
    $app->get('/slim/hello/{name}', function ($request, $response, array $args) {
        $response->getBody()->write("Hello, {$args['name']}");
        return $response->withStatus(201)->withHeader('X-Method', $request->getMethod());
    });
    $app->get('/slim/mixed/:a/{b}', fn($request, $response, array $args) => $args);
    $app->get('/slim/all/:id', fn(...$all) => ['count' => count($all), 'second' => $all[1] instanceof Response, 'third' => $all[2]]);
    $app->get('/slim/one/:id', fn(Request $req) => ['id' => $req->param('id')]);
    $app->get('/slim/controller/:id', [Fixture\UserController::class, 'profile']);
    $app->get('/slim/gated/:id', [Fixture\UserController::class, 'gate'], function ($request, $response, array $args) {
        return $response->withHeader('X-Id', $args['id']);
    });

    // PSR-7: middleware replaces the request with copies, handlers read it through the interface.
    $app->use('/psr', static fn(Request $req, callable $next) => $next($req->withAttribute('who', 'middleware')));
    $app->use('/psr-old', static fn(Request $req, callable $next) => $next(
        $req->withUri($req->getUri()->withPath('/psr/info')->withQuery('moved=1'))
    ));
    $app->all('/psr/info', fn(Request $req) => [
        'method' => $req->getMethod(),
        'uri' => (string)$req->getUri(),
        'target' => $req->getRequestTarget(),
        'query' => $req->getQueryParams(),
        'parsed' => $req->getParsedBody(),
        'body' => (string)$req->getBody(),
        'agent' => $req->getHeaderLine('X-Agent'),
        'agents' => $req->getHeader('x-agent'),
        'hasHost' => $req->hasHeader('HOST'),
        'who' => $req->getAttribute('who'),
        'missing' => $req->getAttribute('nothing', 'fallback'),
        'remote' => $req->getServerParams()['REMOTE_ADDR'] ?? null,
        'cookies' => $req->getCookieParams(),
        'version' => $req->getProtocolVersion(),
        'path' => $req->path(),
    ]);
    $app->get('/psr/response', fn() => (new Response())
        ->withStatus(201, 'Made')
        ->withHeader('X-Multi', 'a')
        ->withAddedHeader('x-multi', 'b')
        ->withHeader('Content-Type', 'text/plain')
        ->withCookie('one', '1')
        ->withCookie('two', '2', 60, '/', '', false, true)
        ->withBody(Stream::from('from a stream')));
    $app->get('/psr/foreign', fn() => new Fixture\ForeignResponse(
        Response::text('foreign', 202)->withHeader('X-Foreign', 'yes')
    ));
    $app->get('/psr/streamed', fn() => (new Response())->withBody(Stream::open(__DIR__ . '/public/hello.txt')));
    $app->get('/psr/invalid', fn() => (new Response())->withHeader('X-Bad', "a\r\nSet-Cookie: injected=1"));
    $app->post('/psr/upload', function (Request $req) {
        $file = $req->getUploadedFiles()['doc'];
        $content = (string)$file->getStream();
        $file->moveTo(__DIR__ . '/uploaded.txt');
        return [
            'name' => $file->getClientFilename(),
            'type' => $file->getClientMediaType(),
            'size' => $file->getSize(),
            'content' => $content,
            'who' => $req->getAttribute('who'),
            'saved' => file_get_contents(__DIR__ . '/uploaded.txt'),
        ];
    });

    // Authentication: every route answers with who the guard let through.
    $who = fn(Request $req) => ['user' => $req->user(), 'current' => Request::current()->user()];
    $token = 'fixture-token-0123456789';
    $jwt = new Jwt(str_repeat('k', 32), issuer: 'kuman');
    $users = ['ana' => password_hash('pa:ss', PASSWORD_BCRYPT, ['cost' => 4])];

    $app->get('/auth/basic', Auth::basic($users, 'Say "hi"'), $who);
    $app->get('/auth/basic-callback', Auth::basic(fn(string $user, string $password) => $user === 'ana' && $password === 'x'), $who);
    $app->get('/auth/bearer', Auth::bearer([$token => 'ana'], query: 'access_token'), $who);
    $app->get('/auth/numeric', Auth::bearer(['1234567890123456' => 'ana']), $who);
    $app->get('/auth/bearer-callback', Auth::bearer(fn(string $given) => $given === 'good' ? ['id' => 1] : false), $who);
    $app->get('/auth/key', Auth::apiKey([$token => 'ci']), $who);
    $app->get('/auth/key-named', Auth::apiKey([$token => 'ci'], 'X-Other', 'key'), $who);
    $app->post('/auth/jwt/login', fn(Request $req) => ['token' => $jwt->issue(['sub' => 'ana'])]);
    $app->get('/auth/jwt', Auth::jwt($jwt), $who);
    $app->get('/auth/jwt-resolved', Auth::jwt($jwt, fn(array $claims) => strtoupper($claims['sub'])), $who);

    $app->post('/auth/login', function (Request $req) {
        Auth::login($req, $req->post('id'));
        return Response::json(['stored' => is_file(__DIR__ . '/runtime/sessions/session_' . $req->sessionId())])
            ->withCookie('theme', 'dark');
    });
    $app->post('/auth/logout', fn(Request $req) => Auth::logout($req, ['out' => true]));
    $app->get('/auth/session', Auth::session(), $who);
    $app->get('/auth/session-page', Auth::session(redirect: '/login'), $who);
    $app->get('/auth/session-resolved', Auth::session(fn(int|string $id) => $id === 'gone' ? null : "user $id"), $who);
    $app->get('/auth/any', Auth::any(Auth::session(), Auth::bearer([$token => 'token user'])), $who);

    $shared = Auth::bearer([$token => 'ana']);
    $app->get('/auth/open', $shared->optional(), $who);
    $app->get('/auth/shared', $shared, $who);
    $app->get('/auth/custom', $shared->onDeny(fn(Request $req, Response $default) => $default->withBody(Stream::from('custom'))), $who);
    $app->use('/auth/zone', $shared);
    $app->get('/auth/zone/page', $who);
    $app->group('/auth/team', $shared, function (App $team) use ($who) {
        $team->get('/page', $who);
    });
    $app->ws('/auth/socket', Auth::bearer([$token => 'ana'], query: 'token'), [
        'open' => fn($conn, Request $req) => $conn->send('hi ' . $req->user()),
    ]);
    $app->sse('/auth/stream', Auth::bearer([$token => 'ana'], query: 'token'), function (Request $req, Sse $sse) {
        $sse->send((string)$req->user());
        $sse->close();
    });

    // Validation: handlers ask for a Dto and return what they got.
    $app->post('/v/users', fn(Fixture\CreateUser $user) => $user);
    $app->post('/v/orders', fn(Fixture\Order $order) => $order);
    $app->get('/v/search', fn(Fixture\Search $search) => $search);
    $app->post('/v/search', fn(#[Query] Fixture\Search $search) => $search);
    $app->patch('/v/promoted', fn(Fixture\Promoted $user) => ['email' => $user->email, 'role' => $user->role]);
    $app->post('/v/messaged', fn(Fixture\Messaged $input) => $input);
    $app->post('/v/attachment', fn(Fixture\Attachment $input) => ['title' => $input->title, 'content' => (string)$input->doc->getStream()]);
    $app->post('/v/guarded', Auth::bearer(fn(string $token) => $token === 'good' ? ['id' => 1] : null), fn(Fixture\CreateUser $user, Request $req) => [
        'user' => $req->user(),
        'current' => Request::current()->user(),
        'email' => $user->email,
    ]);
    $app->post('/v/custom', fn(Fixture\Address $address) => $address);
    $app->post('/v/custom-broken', fn(Fixture\Address $address) => $address);
    $app->onInvalid(function (Invalid $e, Request $req) {
        return match ($req->path()) {
            '/v/custom' => Response::json(['fields' => array_column($e->details, 'field')], 400),
            '/v/custom-broken' => throw new RuntimeException('onInvalid failure'),
            default => $e->response,
        };
    });

    // Injection: parameters come from the path, the request and the app.
    $app->get('/i/path/:id', fn(int $id) => ['id' => $id]);
    $app->get('/i/plan/:plan', fn(Fixture\Plan $plan) => ['plan' => $plan]);
    $app->get('/i/params', fn(
        #[Query] #[Min(1)] int $page = 1,
        #[Query('per_page')] int $perPage = 20,
        #[Header] string $xTenant = 'none',
        #[Cookie('sid')] ?string $session = null,
    ) => compact('page', 'perPage', 'xTenant', 'session'));
    $app->get('/i/required-header', fn(#[Header('x-key')] string $key) => $key);
    $app->post('/i/body-field', fn(#[Body] string $name, #[Body] int $age = 1) => compact('name', 'age'));
    $app->post('/i/mixed/:id', fn(Request $req, Response $res, Fixture\Address $address, array $args, int $id, int $limit = 5) => [
        'method' => $req->method(),
        'city' => $address->city,
        'args' => $args,
        'id' => $id,
        'limit' => $limit,
        'response' => $res->getStatusCode(),
    ]);
    $app->get('/i/service', fn(Fixture\Clock $clock) => ['calls' => ++$clock->calls]);
    $app->get('/i/scoped', fn(Fixture\Tenant $tenant, Fixture\Tenant $again, #[Depends(Fixture\TenantName::class)] string $name) => [
        'same' => $tenant === $again,
        'name' => $name,
        'call' => $tenant->call,
    ]);
    $app->get('/i/abort', fn(#[Depends([Fixture\Deps::class, 'deny'])] string $denied) => 'unreachable');
    $app->post('/i/both', fn(#[Depends([Fixture\Deps::class, 'needsHeader'])] int $need, Fixture\Address $address) => $need);
    $app->get('/i/taken', function (Fixture\Clock $clock) {
        throw new Invalid([['field' => 'email', 'rule' => 'taken', 'message' => 'Already registered.']]);
    });
    $app->get('/i/plain-abort', function () { throw new Abort(Response::text('teapot', 418)); });
    $app->get('/i/controller/:id', [Fixture\InjectedController::class, 'show']);
    $app->get('/i/controller-plain', [Fixture\InjectedController::class, 'plain']);
    $app->get('/i/controller-slim/:id', [Fixture\InjectedController::class, 'slim']);
    $app->get('/slim/named/:id', fn($request, $response, $id) => ['third' => $id]);
    $app->get('/slim/untyped/:id', fn($id) => ['request' => $id instanceof Request]);
    $app->get('/i/slow', fn(#[Depends([Fixture\Deps::class, 'slowName'])] string $round, #[Header('x-round')] string $header) => compact('round', 'header'));

    // Registered after the routes that use them.
    $clock = new Fixture\Clock();
    $app->provide(Fixture\Clock::class, $clock);
    $app->scoped(Fixture\Tenant::class, function (Request $req, Fixture\Clock $clock) {
        return new Fixture\Tenant((string)$req->header('x-tenant', 'acme'), ++$clock->calls);
    });

    // Documentation: the fixture has a route for each thing the document must describe.
    $guard = Auth::bearer(fn(string $token) => $token === 'good' ? 1 : null);
    $app->group('/d', function (App $d) use ($guard, $users) {
        $d->get('/items/:id(\d+)', #[Summary('One item')] fn(int $id) => $id);
        $d->get('/names/:name([a-z]+)', fn(string $name) => $name);
        $d->get('/opt[/{part}]', fn() => 'ok');
        $d->get('/rest/*', fn() => 'ok');
        $d->all('/every', fn() => 'ok');
        $d->post('/users', fn(Fixture\CreateUser $user) => $user);
        $d->post('/orders', fn(Fixture\Order $order) => 'ok');
        $d->post('/upload', fn(Fixture\Attachment $input) => 'ok');
        $d->post('/fields', fn(#[Body] string $name, #[Body] int $age = 1) => 'ok');
        $d->get('/search', fn(Fixture\Search $search) => 'ok');
        $d->delete('/filter', fn(#[Query] Fixture\Search $search) => 'ok');
        $d->get('/depends', fn(#[Depends([Fixture\Deps::class, 'needsHeader'])] int $need) => $need);
        $d->get('/users/:id', [Fixture\InjectedController::class, 'show']);
        $d->get('/list', [Fixture\DocumentedController::class, 'list']);
        $d->get('/many', [Fixture\DocumentedController::class, 'many']);
        $d->get('/secret', [Fixture\DocumentedController::class, 'secret']);
        $d->get('/closure-hidden', #[Hidden] fn() => 'ok');
        $d->get('/plain', fn(Request $req) => 'ok');
        $d->post('/slim/:id', fn($req, $res, array $args) => $res);
        $d->get('/guarded', $guard, fn() => 'ok');
        $d->get('/maybe', $guard->optional(), fn() => 'ok');
        $d->get('/either', Auth::any(Auth::session(), Auth::basic($users)), fn() => 'ok');
        $d->get('/both', $guard, Auth::apiKey(fn() => 1, 'X-Team'), fn() => 'ok');
        $d->ws('/socket', ['message' => fn($conn, $data) => null]);
        $d->sse('/stream', fn(Request $req, Sse $sse) => null);
    });
    $app->use('/d/zone', $guard);
    $app->get('/d/zone/page', fn() => 'ok');
    $app->get('/d/zoned', fn() => 'ok');

    $app->enableDocs(title: 'Fixture & "docs"', version: '2.0.0');
    $app->enableDocs(path: '/locked/docs', spec: '/locked/openapi.json', ui: 'swagger', middleware: [$guard]);
    $app->enableDocs(path: '/redoc', spec: '/redoc.json', ui: 'redoc');
    $app->enableDocs(path: '/no-ui', spec: '/only.json', ui: null);
    $app->group('/grouped', $guard, function (App $grouped) {
        $grouped->enableDocs();
    });

    $app->get('/deferred', function (Request $req) use ($app) {
        $app->after(0.05, fn() => $req->respond(['late' => true]));
        return null;
    });
    $app->get('/after-boom', function () use ($app) {
        $app->after(0.01, function () { throw new RuntimeException('after failure'); });
        return 'ok';
    });
    $app->get('/chunked', function (Request $req) use ($app) {
        $app->after(0.05, function () use ($req) {
            $req->connection->send(new Chunk('second'));
            $req->connection->send(new Chunk(''));
        });
        return new Response(200, ['Transfer-Encoding' => 'chunked'], 'first');
    });
    $app->get('/wait', function (Request $req) {
        Timer::sleep(0.2);
        return ['connection' => $req->connection->id];
    });

    $app->sse('/events', function ($req, Sse $sse) {
        $count = 0;
        $sse->send("first\r\nline", 'greeting', "1\r\nretry: 1");
        $sse->every(0.02, function () use ($sse, &$count) {
            $sse->send(['count' => ++$count]);
            if ($count === 2) {
                $sse->close();
            }
        });
        $sse->onClose(function () { file_put_contents(__DIR__ . '/sse-closed', '1'); });
    });
    $app->sse('/events-boom', function () { throw new RuntimeException('sse failure'); });

    $app->ws('/chat', [
        'open' => fn($conn, Request $req) => $conn->send('welcome ' . $req->get('name')),
        'message' => fn($conn, string $data) => $conn->send("echo:$data"),
    ]);
    $app->ws('/untyped', [
        'open' => fn($conn) => $conn->send('ready'),
        'message' => fn($conn, $data) => $conn->send('got ' . var_export($data, true)),
    ]);
    $app->ws('/locked', static fn() => Response::text('denied', 401), []);
    $app->ws('/ws-open-boom', ['open' => function () { throw new RuntimeException('open failure'); }]);
    $app->ws('/ws-message-boom', ['message' => function () { throw new RuntimeException('message failure'); }]);
};
