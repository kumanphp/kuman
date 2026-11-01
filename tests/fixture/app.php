<?php

use Kuman\App;
use Kuman\Request;
use Kuman\Response;
use Kuman\Sse;
use Kuman\Stream;
use Workerman\Protocols\Http\Chunk;
use Workerman\Timer;

require_once __DIR__ . '/ForeignResponse.php';

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
