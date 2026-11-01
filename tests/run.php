<?php

// Integration tests: each suite boots a real server through bin/kuman.

const PORT = 18642;

$root = dirname(__DIR__);
require "$root/vendor/autoload.php";
require "$root/tests/fixture/ForeignResponse.php";
$tmp = "$root/tests/tmp";
$failures = 0;
$passes = 0;

function check(string $name, mixed $actual, mixed $expected = true): void
{
    global $failures, $passes;
    if ($actual === $expected) {
        $passes++;
        return;
    }
    $failures++;
    echo "  FAIL $name\n    expected: ", var_export($expected, true), "\n    actual:   ", var_export($actual, true), "\n";
}

function kuman(string $arguments, array $env = []): void
{
    global $root, $tmp;
    $prefix = '';
    foreach ($env as $key => $value) {
        $prefix .= "$key=" . escapeshellarg((string)$value) . ' ';
    }
    exec("cd " . escapeshellarg($tmp) . " && {$prefix}php " . escapeshellarg("$root/bin/kuman") . " $arguments 2>&1", $output, $status);
    if ($status !== 0) {
        throw new RuntimeException("kuman $arguments failed: " . implode("\n", $output));
    }
}

function waitFor(callable $condition, float $seconds = 5): bool
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        if ($condition()) {
            return true;
        }
        usleep(50000);
    }
    return false;
}

function connect(float $timeout = 3)
{
    $socket = stream_socket_client('tcp://127.0.0.1:' . PORT, $code, $message, 2);
    if ($socket === false) {
        throw new RuntimeException($message);
    }
    stream_set_timeout($socket, (int)$timeout);
    return $socket;
}

function readHead($socket): string
{
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $byte = fread($socket, 1);
        if ($byte === '' || $byte === false) {
            break;
        }
        $head .= $byte;
    }
    return $head;
}

function readBody($socket, string $head): string
{
    $body = '';
    if (preg_match('/content-length: (\d+)/i', $head, $match)) {
        $length = (int)$match[1];
        while (strlen($body) < $length) {
            $chunk = fread($socket, $length - strlen($body));
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $body .= $chunk;
        }
    }
    return $body;
}

/**
 * @return array{status: int, head: string, body: string}
 */
function http(string $method, string $path, array $headers = [], string $body = ''): array
{
    $socket = connect();
    $request = "$method $path HTTP/1.1\r\nHost: test\r\n";
    foreach ($headers + ['Connection' => 'close', 'Content-Length' => strlen($body)] as $name => $value) {
        $request .= "$name: $value\r\n";
    }
    fwrite($socket, "$request\r\n$body");
    $head = readHead($socket);
    $content = $method === 'HEAD' ? '' : readBody($socket, $head);
    fclose($socket);
    return ['status' => (int)substr($head, 9, 3), 'head' => $head, 'body' => $content];
}

function hasHeader(array $response, string $header): bool
{
    return stripos($response['head'], "\r\n$header") !== false;
}

function pid(): ?int
{
    return json_decode(http('GET', '/pid')['body'], true)['pid'] ?? null;
}

function openSocket(string $path)
{
    $socket = connect();
    $key = base64_encode(random_bytes(16));
    fwrite($socket, "GET $path HTTP/1.1\r\nHost: test\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
    return [$socket, readHead($socket)];
}

function frame(string $payload, string $mask = "\x01\x02\x03\x04"): string
{
    $masked = '';
    for ($i = 0; $i < strlen($payload); $i++) {
        $masked .= $payload[$i] ^ $mask[$i % 4];
    }
    return "\x81" . chr(0x80 | strlen($payload)) . $mask . $masked;
}

function readFrame($socket): ?string
{
    $header = fread($socket, 2);
    if ($header === false || strlen($header) < 2) {
        return null;
    }
    $length = ord($header[1]) & 127;
    return $length === 0 ? '' : fread($socket, $length);
}

function routing(): void
{
    check('string is html', http('GET', '/')['body'], 'hello');
    check('html content type', hasHeader(http('GET', '/'), 'Content-Type: text/html'));
    check('param', http('GET', '/users/42')['body'], '{"id":"42"}');
    check('param is decoded', http('GET', '/users/a%20b')['body'], '{"id":"a b"}');
    check('constrained param', http('GET', '/digits/7')['body'], '{"id":"7"}');
    check('constraint rejects', http('GET', '/digits/abc')['status'], 404);
    check('wildcard', http('GET', '/files/a/b.txt')['body'], '{"path":"a/b.txt"}');
    check('trailing slash', http('GET', '/users/42/')['body'], '{"id":"42"}');
    check('post json', http('POST', '/echo?q=1', ['Content-Type' => 'application/json'], '{"a":2}')['body'], '{"a":2,"q":"1"}');
    check('all methods', http('DELETE', '/any')['body'], 'DELETE');
    check('current request', http('GET', '/current')['body'], '{"same":true}');
    check('redirect', hasHeader(http('GET', '/redirect'), 'Location: /target'));
    check('custom 404', http('GET', '/missing')['body'], 'custom 404');

    $notAllowed = http('POST', '/');
    check('405 status', $notAllowed['status'], 405);
    check('405 allow header', hasHeader($notAllowed, 'Allow: GET'));
}

function middleware(): void
{
    check('global', hasHeader(http('GET', '/'), 'X-Global: yes'));
    check('global on 404', hasHeader(http('GET', '/missing'), 'X-Global: yes'));
    check('prefix applies', hasHeader(http('GET', '/scoped/page'), 'X-Scoped: yes'));
    check('prefix does not leak', hasHeader(http('GET', '/'), 'X-Scoped'), false);
    check('prefix respects boundary', hasHeader(http('GET', '/scopedx'), 'X-Scoped'), false);
    check('route middleware stops the chain', http('GET', '/guarded')['status'], 403);
    check('group route', http('GET', '/api/ping')['body'], 'pong');
    check('group middleware', hasHeader(http('GET', '/api/ping'), 'X-Group: yes'));
    check('nested group inherits', hasHeader(http('GET', '/api/v1/ping'), 'X-Group: yes'));
    check('use inside group', hasHeader(http('GET', '/api/v1/ping'), 'X-Nested: yes'));
    check('use inside group stays there', hasHeader(http('GET', '/api/ping'), 'X-Nested'), false);
    check('group middleware stays there', hasHeader(http('GET', '/outside'), 'X-Group'), false);
}

function connectionHandling(): void
{
    $socket = connect();
    fwrite($socket, "GET / HTTP/1.1\r\nHost: test\r\n\r\nGET /users/1 HTTP/1.1\r\nHost: test\r\n\r\n");
    check('keep-alive first', readBody($socket, readHead($socket)), 'hello');
    check('keep-alive second', readBody($socket, readHead($socket)), '{"id":"1"}');
    fclose($socket);

    $socket = connect();
    fwrite($socket, "GET / HTTP/1.0\r\nHost: test\r\n\r\n");
    readBody($socket, readHead($socket));
    check('http/1.0 closes', fread($socket, 1), '');
    fclose($socket);

    $socket = connect();
    fwrite($socket, "HEAD / HTTP/1.1\r\nHost: test\r\n\r\nGET /users/2 HTTP/1.1\r\nHost: test\r\n\r\n");
    $head = readHead($socket);
    check('head keeps the length', stripos($head, 'Content-Length: 5') !== false);
    $next = readHead($socket);
    check('head has no body', str_starts_with($next, 'HTTP/1.1 200'));
    check('request after head', readBody($socket, $next), '{"id":"2"}');
    fclose($socket);

    $socket = connect();
    fwrite($socket, "HEAD /hello.txt HTTP/1.1\r\nHost: test\r\n\r\nGET /users/3 HTTP/1.1\r\nHost: test\r\n\r\n");
    readHead($socket);
    check('head on a file has no body', str_starts_with(readHead($socket), 'HTTP/1.1 200'));
    fclose($socket);

    $socket = connect();
    fwrite($socket, "GET /hello.txt HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
    readBody($socket, readHead($socket));
    check('files honour Connection: close', fread($socket, 1), '');
    fclose($socket);

    $socket = connect();
    fwrite($socket, "GET /chunked HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
    readHead($socket);
    $stream = '';
    while (!str_ends_with($stream, "0\r\n\r\n") && !feof($socket)) {
        $stream .= (string)fread($socket, 1024);
    }
    check('chunked survives Connection: close', $stream, "5\r\nfirst\r\n6\r\nsecond\r\n0\r\n\r\n");
    fclose($socket);
}

function errors(): void
{
    $before = pid();
    $response = http('GET', '/boom');
    check('500 status', $response['status'], 500);
    check('500 hides details', str_contains($response['body'], 'secret detail'), false);
    http('GET', '/after-boom');
    usleep(100000);
    check('failing timers keep the worker alive', pid(), $before);
    check('failure is logged', str_contains((string)file_get_contents($GLOBALS['tmp'] . '/runtime/kuman.log'), 'after failure'));
}

function statics(): void
{
    check('serves a file', http('GET', '/hello.txt')['body'], "static hello\n");
    check('nested file', http('GET', '/assets/app.css')['body'], "body {}\n");
    check('mime type', hasHeader(http('GET', '/assets/app.css'), 'Content-Type: text/css'));
    check('encoded traversal', http('GET', '/assets/%2e%2e/%2e%2e/app.php')['status'], 404);
    check('raw traversal', http('GET', '/assets/../../app.php')['status'], 400);
    check('backslash', http('GET', '/assets\\app.css')['status'], 400);
    check('dotfile', http('GET', '/.secret')['status'], 404);
    check('encoded dotfile', http('GET', '/%2esecret')['status'], 404);
    check('well-known', http('GET', '/.well-known/ok.txt')['body'], "known\n");
    check('php source', http('GET', '/script.php')['status'], 404);
    check('uppercase php source', http('GET', '/script.PHP')['status'], 404);
    check('prefix guard on static', http('GET', '/private/note.txt')['status'], 401);
    check('encoded prefix guard', http('GET', '/%70rivate/note.txt')['status'], 401);
    // Workerman parses "private" as the host here, so the guarded file is out of reach.
    check('double slash misses the guarded file', http('GET', '//private/note.txt')['status'], 404);
    check('post is not static', http('POST', '/hello.txt')['status'], 404);

    $head = http('GET', '/hello.txt')['head'];
    preg_match('/Last-Modified: (.*)\r\n/', $head, $match);
    check('if-modified-since', http('GET', '/hello.txt', ['If-Modified-Since' => $match[1]])['status'], 304);

    $range = http('GET', '/hello.txt', ['Range' => 'bytes=7-11']);
    check('range status', $range['status'], 206);
    check('range body', $range['body'], 'hello');
    check('range beyond the end', http('GET', '/hello.txt', ['Range' => 'bytes=99-'])['status'], 416);
    check('head on file', str_contains(http('HEAD', '/hello.txt')['head'], 'Content-Length: 13'));

    $large = http('GET', '/large.bin');
    check('large file over Connection: close', md5($large['body']), md5_file($GLOBALS['tmp'] . '/public/large.bin'));
}

function realtime(): void
{
    $started = microtime(true);
    check('deferred response', http('GET', '/deferred')['body'], '{"late":true}');
    check('deferred waited', microtime(true) - $started >= 0.05);

    $socket = connect();
    fwrite($socket, "GET /events HTTP/1.1\r\nHost: test\r\n\r\n");
    $head = readHead($socket);
    check('sse type', stripos($head, 'Content-Type: text/event-stream') !== false);
    check('sse is not length delimited', stripos($head, 'Content-Length'), false);
    $stream = stream_get_contents($socket);
    check('sse stream', $stream, "event: greeting\nid: 1retry: 1\ndata: first\ndata: line\n\ndata: {\"count\":1}\n\ndata: {\"count\":2}\n\n");
    fclose($socket);
    check('sse close callback', waitFor(fn() => is_file($GLOBALS['tmp'] . '/sse-closed')));

    check('sse failure leaves the worker up', is_int(pid()));
    $socket = connect();
    fwrite($socket, "GET /events-boom HTTP/1.1\r\nHost: test\r\n\r\n");
    readHead($socket);
    check('sse failure closes the stream', stream_get_contents($socket), '');
    fclose($socket);

    check('every runs', waitFor(fn() => (json_decode(http('GET', '/ticks')['body'], true)['ticks'] ?? 0) >= 2));
    check('custom process', waitFor(fn() => is_file($GLOBALS['tmp'] . '/beacon-file')));
}

function websockets(): void
{
    [$socket, $head] = openSocket('/chat?name=kuman');
    check('upgrade', substr($head, 9, 3), '101');
    check('open sees the request', readFrame($socket), 'welcome kuman');
    fwrite($socket, frame('halo'));
    check('message', readFrame($socket), 'echo:halo');
    fclose($socket);

    check('http still works beside websocket', http('GET', '/')['body'], 'hello');
    check('plain get on ws route', http('GET', '/chat')['status'], 426);

    [$socket, $head] = openSocket('/locked');
    check('route middleware guards the upgrade', substr($head, 9, 3), '401');
    fclose($socket);

    $before = pid();
    [$socket] = openSocket('/ws-open-boom');
    check('open failure closes', stream_get_contents($socket), '');
    fclose($socket);
    [$socket] = openSocket('/ws-message-boom');
    fwrite($socket, frame('x'));
    check('message failure closes', stream_get_contents($socket), '');
    fclose($socket);
    check('websocket failures keep the worker alive', pid(), $before);

    // The same bytes on both protocols must not cross Workerman's decode cache.
    $replay = frame('poison', "\x05\x06\x07\x08");
    [$socket] = openSocket('/chat?name=a');
    readFrame($socket);
    fwrite($socket, $replay);
    check('frame before replay', readFrame($socket), 'echo:poison');
    $plain = connect(1);
    fwrite($plain, $replay);
    fread($plain, 1);
    fclose($plain);
    check('replayed frame keeps the worker alive', pid(), $before);
    fwrite($socket, $replay);
    check('socket survives replay', readFrame($socket), 'echo:poison');
    fclose($socket);

    // Cached HTTP request bytes sent as a frame must not reach the message callback.
    $request = "GET / HTTP/1.1\r\nHost: test\r\n\r\n";
    $keep = connect();
    fwrite($keep, $request);
    readBody($keep, readHead($keep));
    fwrite($keep, $request);
    readBody($keep, readHead($keep));
    fclose($keep);
    [$socket] = openSocket('/untyped');
    readFrame($socket);
    fwrite($socket, $request);
    check('http bytes on a websocket close it', stream_get_contents($socket), '');
    fclose($socket);
    check('worker survives http bytes on a websocket', pid(), $before);
}

function concurrency(): void
{
    // Two rounds: the second is served from Workerman's request cache.
    foreach ([1, 2] as $round) {
        $sockets = [];
        foreach (range(1, 3) as $i) {
            $sockets[$i] = connect();
            fwrite($sockets[$i], "GET /wait HTTP/1.1\r\nHost: test\r\n\r\n");
        }
        $started = microtime(true);
        $connections = [];
        foreach ($sockets as $socket) {
            $connections[] = json_decode(readBody($socket, readHead($socket)), true)['connection'] ?? null;
            fclose($socket);
        }
        check("round $round every client answered", count(array_filter($connections)), 3);
        check("round $round own connection", count(array_unique($connections)), 3);
        check("round $round overlapped", microtime(true) - $started < 0.5);
    }
}

function reloading(): void
{
    global $tmp;
    check('before reload', http('GET', '/version')['body'], 'v1');
    $source = (string)file_get_contents("$tmp/app.php");
    file_put_contents("$tmp/app.php", str_replace("'v1'", "'v2'", $source));
    kuman('reload');
    check('reload picks up app.php', waitFor(fn() => (http('GET', '/version')['body'] ?? '') === 'v2'));
    file_put_contents("$tmp/app.php", $source);
    kuman('reload');
    waitFor(fn() => (http('GET', '/version')['body'] ?? '') === 'v1');
}

function watching(): void
{
    global $root, $tmp;
    $process = proc_open(
        ['php', "$root/bin/kuman", 'start'],
        [1 => ['file', "$tmp/foreground.log", 'w'], 2 => ['file', "$tmp/foreground.log", 'a']],
        $pipes,
        $tmp,
        ['PORT' => PORT, 'PATH' => getenv('PATH')]
    );
    check('foreground start', waitFor(fn() => @fsockopen('127.0.0.1', PORT) !== false));

    sleep(2);
    $source = (string)file_get_contents("$tmp/app.php");
    file_put_contents("$tmp/app.php", str_replace("'v1'", "'v3'", $source));
    check('watcher reloads on change', waitFor(fn() => (@file_get_contents('http://127.0.0.1:' . PORT . '/version') ?: '') === 'v3', 8));

    sleep(2);
    file_put_contents("$tmp/app.php", "<?php this is broken");
    sleep(2);
    check('watcher skips a broken file', (@file_get_contents('http://127.0.0.1:' . PORT . '/version') ?: ''), 'v3');

    file_put_contents("$tmp/app.php", $source);
    kuman('stop');
    proc_close($process);
}

function configuration(): void
{
    global $root, $tmp;
    $run = function (string $command) use ($root, $tmp): array {
        exec('cd ' . escapeshellarg($tmp) . " && $command php " . escapeshellarg("$root/bin/kuman") . ' 2>&1', $output, $status);
        return [$status, implode("\n", $output)];
    };
    $withArguments = function (string $arguments) use ($root, $tmp): array {
        exec('cd ' . escapeshellarg($tmp) . ' && php ' . escapeshellarg("$root/bin/kuman") . " $arguments 2>&1", $output, $status);
        return [$status, implode("\n", $output)];
    };

    check('rejects a bad port', $run('PORT=99999')[0], 1);
    check('rejects a bad loop', $run('KUMAN_LOOP=nope')[0], 1);
    check('rejects an unknown command', $withArguments('frobnicate')[0], 1);
    check('help', $withArguments('--help')[0], 0);
    check('missing app file', $withArguments('start --app=nothing.php')[1], "Kuman: Application file not found: $tmp/nothing.php");

    file_put_contents("$tmp/broken.php", "<?php this is broken");
    [$status, $output] = $withArguments('start --app=broken.php');
    check('syntax error stops the start', $status, 1);
    check('syntax error is reported', str_contains($output, 'syntax error'));
}

function prepare(): void
{
    global $root, $tmp;
    exec('rm -rf ' . escapeshellarg($tmp));
    exec('cp -r ' . escapeshellarg("$root/tests/fixture") . ' ' . escapeshellarg($tmp));
    file_put_contents("$tmp/public/large.bin", random_bytes(3 * 1024 * 1024));
}

function throws(callable $callback, string $class = Throwable::class): bool
{
    try {
        $callback();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}

function psr7Objects(): void
{
    $uri = new Kuman\Uri('HTTP://User:pw@Example.COM:80/a b/%7e?x=1 2#frag');
    check('uri scheme', $uri->getScheme(), 'http');
    check('uri host', $uri->getHost(), 'example.com');
    check('uri drops the default port', $uri->getPort(), null);
    check('uri authority', $uri->getAuthority(), 'User:pw@example.com');
    check('uri encodes the path once', $uri->getPath(), '/a%20b/%7e');
    check('uri query', $uri->getQuery(), 'x=1%202');
    check('uri string', (string)$uri, 'http://User:pw@example.com/a%20b/%7e?x=1%202#frag');
    check('uri keeps a custom port', $uri->withPort(8080)->getPort(), 8080);
    check('uri is immutable', $uri->getPort(), null);
    check('uri rejects a bad port', throws(fn() => $uri->withPort(70000), InvalidArgumentException::class));
    check('uri relative', (string)new Kuman\Uri('/p?q=1'), '/p?q=1');
    check('uri path under an authority', (string)(new Kuman\Uri('//h'))->withPath('x'), '//h/x');
    check('uri without authority has no double slash', (string)(new Kuman\Uri())->withPath('//x'), '/x');
    check('uri rejects garbage', throws(fn() => new Kuman\Uri('http:///x'), InvalidArgumentException::class));

    $stream = Kuman\Stream::from('hello');
    check('stream size', $stream->getSize(), 5);
    check('stream read', $stream->read(2), 'he');
    check('stream tell', $stream->tell(), 2);
    check('stream rest', $stream->getContents(), 'llo');
    check('stream eof', $stream->eof());
    check('stream to string rewinds', (string)$stream, 'hello');
    $stream->seek(0, SEEK_END);
    $stream->write('!');
    check('stream write', (string)$stream, 'hello!');
    $stream->close();
    check('stream after close', throws(fn() => $stream->read(1), RuntimeException::class));
    check('stream size after close', $stream->getSize(), null);

    $response = new Kuman\Response(200, ['Content-Type' => 'text/plain']);
    $missing = $response->withStatus(404);
    check('response is immutable', $response->getStatusCode(), 200);
    check('response status', $missing->getStatusCode(), 404);
    check('default reason phrase', $missing->getReasonPhrase(), 'Not Found');
    check('custom reason phrase', $response->withStatus(404, 'Gone Fishing')->getReasonPhrase(), 'Gone Fishing');
    check('status below range', throws(fn() => new Kuman\Response(99), InvalidArgumentException::class));
    check('status above range', throws(fn() => $response->withStatus(600), InvalidArgumentException::class));
    check('header is case-insensitive', $response->getHeaderLine('content-TYPE'), 'text/plain');
    check('header keeps its case', array_keys($response->getHeaders()), ['Content-Type']);
    check('replaced header', $response->withHeader('content-type', 'text/html')->getHeaders(), ['content-type' => ['text/html']]);
    check('added header', $response->withAddedHeader('Content-Type', 'x')->getHeader('Content-Type'), ['text/plain', 'x']);
    check('added header line', $response->withAddedHeader('Content-Type', 'x')->getHeaderLine('Content-Type'), 'text/plain, x');
    check('removed header', $response->withoutHeader('CONTENT-TYPE')->hasHeader('Content-Type'), false);
    check('headers are immutable', $response->getHeader('Content-Type'), ['text/plain']);
    check('missing header', $response->getHeader('X-None'), []);
    check('bad header name', throws(fn() => $response->withHeader('Bad Name', 'x'), InvalidArgumentException::class));
    check('header injection', throws(fn() => $response->withHeader('X-A', "a\r\nX-B: b"), InvalidArgumentException::class));
    check('empty header values', throws(fn() => $response->withHeader('X-A', []), InvalidArgumentException::class));
    check('bad protocol version', throws(fn() => $response->withProtocolVersion('x'), InvalidArgumentException::class));
    check('protocol version', $response->withProtocolVersion('1.0')->getProtocolVersion(), '1.0');

    $body = $response->getBody();
    check('body is stable', $response->getBody() === $body);
    check('body from the constructor', (string)(new Kuman\Response(200, [], 'abc'))->getBody(), 'abc');
    $replaced = $response->withBody(Kuman\Stream::from('new'));
    check('withBody', (string)$replaced->getBody(), 'new');
    check('withBody is immutable', (string)$response->getBody(), '');

    $wire = (string)$replaced->withStatus(404)->withAddedHeader('X-Multi', 'a')->withAddedHeader('X-Multi', 'b')
        ->withCookie('one', '1')->withCookie('two', '2', 60, '/', '', true, true, 'Lax')->toWorkerman();
    check('wire status line', str_starts_with($wire, "HTTP/1.1 404 Not Found\r\n"));
    check('wire joins repeated headers', str_contains($wire, "\r\nX-Multi: a, b\r\n"));
    check('wire keeps cookies apart', str_contains($wire, "\r\nSet-Cookie: one=1\r\nSet-Cookie: two=2; Max-Age=60; Path=/; Secure; HttpOnly; SameSite=Lax\r\n"));
    check('wire length', str_contains($wire, "\r\nContent-Length: 3\r\n\r\nnew"));

    check('from a string', Kuman\Response::from('x')->getHeaderLine('Content-Type'), 'text/html; charset=utf-8');
    check('from an array', (string)Kuman\Response::from(['a' => 1])->getBody(), '{"a":1}');
    check('from itself', Kuman\Response::from($response) === $response);
    $foreign = Kuman\Response::from(new Fixture\ForeignResponse(Kuman\Response::text('hi', 202)->withHeader('X-F', 'y')));
    check('from another library', [$foreign->getStatusCode(), $foreign->getHeaderLine('X-F'), (string)$foreign->getBody()], [202, 'y', 'hi']);
    $workerman = Kuman\Response::from(new Workerman\Protocols\Http\Response(201, ['X-W' => 'z'], 'w'));
    check('from Workerman', [$workerman->getStatusCode(), $workerman->getHeaderLine('X-W'), (string)$workerman->getBody()], [201, 'z', 'w']);

    $file = dirname(__DIR__) . '/tests/fixture/public/hello.txt';
    $served = Kuman\Response::file($file);
    check('file body', (string)$served->getBody(), "static hello\n");
    check('file is sent from disk', $served->toWorkerman()->file['file'], $file);
    check('file slice', (string)$served->withFile($file, 7, 5)->getBody(), 'hello');
    check('file keeps its headers', $served->withHeader('X-A', 'b')->toWorkerman()->file !== null);
    check('withBody replaces the file', $served->withBody(Kuman\Stream::from('x'))->toWorkerman()->file, null);
    check('missing file', Kuman\Response::file("$file.nothing")->getStatusCode(), 404);
    $warnings = 0;
    set_error_handler(function () use (&$warnings) { $warnings++; return true; });
    $missing = Kuman\Response::file("$file.nothing", new Kuman\Request("GET /f HTTP/1.1\r\nHost: t\r\n\r\n"));
    restore_error_handler();
    check('missing file for a request', [$missing->getStatusCode(), $warnings], [404, 0]);

    $raw = "POST /a/b?x=1&y[]=2 HTTP/1.1\r\nHost: Example.com:8080\r\nContent-Type: application/json\r\nX-Agent: one\r\nContent-Length: 7\r\n\r\n{\"k\":1}";
    $request = new Kuman\Request($raw);
    check('request method', $request->getMethod(), 'POST');
    check('request target', $request->getRequestTarget(), '/a/b?x=1&y[]=2');
    check('request uri', (string)$request->getUri(), 'http://example.com:8080/a/b?x=1&y%5B%5D=2');
    check('request uri is stable', $request->getUri() === $request->getUri());
    check('request query', $request->getQueryParams(), ['x' => '1', 'y' => ['2']]);
    check('request header', $request->getHeaderLine('X-AGENT'), 'one');
    check('request has header', $request->hasHeader('host'));
    check('request headers', $request->getHeaders()['x-agent'], ['one']);
    check('request parsed body', $request->getParsedBody(), ['k' => 1]);
    check('request body', (string)$request->getBody(), '{"k":1}');
    check('request body is stable', $request->getBody() === $request->getBody());
    check('request protocol', $request->getProtocolVersion(), '1.1');
    check('request attributes', $request->getAttributes(), []);
    check('request server params', $request->getServerParams()['REQUEST_METHOD'], 'POST');
    check('request without a body has none parsed', (new Kuman\Request("GET / HTTP/1.1\r\nHost: t\r\n\r\n"))->getParsedBody(), null);
    check('request with a text body has none parsed', (new Kuman\Request("POST / HTTP/1.1\r\nHost: t\r\nContent-Type: text/plain\r\n\r\nx"))->getParsedBody(), null);
    check('request with a broken host', (string)(new Kuman\Request("GET /x?y=1 HTTP/1.1\r\nHost: http://\r\n\r\n"))->getUri()->getPath(), '/x');

    $put = $request->withMethod('PUT');
    check('withMethod', [$put->getMethod(), $put->method(), $request->getMethod()], ['PUT', 'PUT', 'POST']);
    check('withMethod rejects garbage', throws(fn() => $request->withMethod('GE T'), InvalidArgumentException::class));

    $headers = $request->withHeader('X-Agent', ['a', 'b']);
    check('withHeader', $headers->getHeader('x-agent'), ['a', 'b']);
    check('withHeader reaches header()', $headers->header('x-agent'), 'a,b');
    check('withHeader leaves the original', $request->header('x-agent'), 'one');
    check('withAddedHeader', $request->withAddedHeader('X-Agent', 'two')->getHeader('X-Agent'), ['one', 'two']);
    check('withoutHeader', $request->withoutHeader('X-Agent')->header('x-agent'), null);

    $moved = $request->withUri(new Kuman\Uri('/other?z=9'));
    check('withUri reaches path()', $moved->path(), '/other');
    check('withUri reaches get()', $moved->get('z'), '9');
    check('withUri target', $moved->getRequestTarget(), '/other?z=9');
    check('withUri keeps the host', $moved->getHeaderLine('Host'), 'Example.com:8080');
    check('withUri leaves the original', $request->path(), '/a/b');
    check('withUri updates the host', $request->withUri(new Kuman\Uri('http://new.test:81/p'))->getHeaderLine('Host'), 'new.test:81');
    check('withUri can preserve the host', $request->withUri(new Kuman\Uri('http://new.test/p'), true)->getHeaderLine('Host'), 'Example.com:8080');
    check('withUri without a path', $request->withUri(new Kuman\Uri('http://new.test'))->path(), '/');

    $target = $request->withRequestTarget('/q?a=1');
    check('withRequestTarget', [$target->path(), $target->get('a'), (string)$target->getUri()], ['/q', '1', 'http://example.com:8080/q?a=1']);
    check('withRequestTarget rejects whitespace', throws(fn() => $request->withRequestTarget('/a b'), InvalidArgumentException::class));

    check('withQueryParams', $request->withQueryParams(['q' => '1'])->get('q'), '1');
    check('withQueryParams leaves the original', $request->get('q'), null);
    check('withCookieParams', $request->withCookieParams(['c' => 'v'])->getCookieParams(), ['c' => 'v']);
    check('withAttribute', $request->withAttribute('a', 1)->getAttribute('a'), 1);
    check('withAttribute leaves the original', $request->getAttribute('a', 'none'), 'none');
    check('withoutAttribute', $request->withAttribute('a', 1)->withoutAttribute('a')->getAttributes(), []);
    check('null attribute', $request->withAttribute('a', null)->getAttribute('a', 'x'), null);
    check('withParsedBody', $request->withParsedBody(['x' => 1])->getParsedBody(), ['x' => 1]);
    check('withParsedBody null', $request->withParsedBody(null)->getParsedBody(), null);
    check('withParsedBody rejects scalars', throws(fn() => $request->withParsedBody('x'), InvalidArgumentException::class));
    check('withProtocolVersion', $request->withProtocolVersion('1.0')->protocolVersion(), '1.0');

    $other = $request->withBody(Kuman\Stream::from('{"k":2}'));
    check('withBody stream', (string)$other->getBody(), '{"k":2}');
    check('withBody reaches post()', $other->post('k'), 2);
    check('withBody parsed', $other->getParsedBody(), ['k' => 2]);
    check('withBody leaves the original', (string)$request->getBody(), '{"k":1}');

    $form = "--XX\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nhi\r\n--XX\r\nContent-Disposition: form-data; name=\"doc\"; filename=\"a.txt\"\r\nContent-Type: text/plain\r\n\r\nfile body\r\n--XX--\r\n";
    $upload = new Kuman\Request("POST /u HTTP/1.1\r\nHost: t\r\nContent-Type: multipart/form-data; boundary=XX\r\nContent-Length: " . strlen($form) . "\r\n\r\n$form");
    $files = $upload->getUploadedFiles();
    $doc = $files['doc'] ?? null;
    check('uploaded file', $doc instanceof Psr\Http\Message\UploadedFileInterface);
    check('uploaded file details', [$doc?->getClientFilename(), $doc?->getClientMediaType(), $doc?->getSize(), $doc?->getError()], ['a.txt', 'text/plain', 9, UPLOAD_ERR_OK]);
    check('uploaded file stream', (string)$doc?->getStream(), 'file body');
    check('multipart parsed body', $upload->getParsedBody(), ['title' => 'hi']);
    check('uploads are stable', $upload->getUploadedFiles() === $files);
    check('no uploads on a form', $request->getUploadedFiles(), []);
    check('withUploadedFiles', $request->withUploadedFiles(['f' => $doc])->getUploadedFiles(), ['f' => $doc]);
    check('withUploadedFiles rejects others', throws(fn() => $request->withUploadedFiles(['f' => 'x']), InvalidArgumentException::class));

    // The request deletes its temporary files when it goes, a copy dropped on the way must not.
    $path = $upload->file('doc')['tmp_name'];
    $copy = $upload->withAttribute('a', 1)->withAttribute('b', 2);
    unset($copy);
    check('a dropped copy keeps the upload', is_file($path));
    $moved = tempnam(sys_get_temp_dir(), 'kuman-test');
    $doc->moveTo($moved);
    check('moveTo', file_get_contents($moved), 'file body');
    check('moveTo twice', throws(fn() => $doc->moveTo($moved), RuntimeException::class));
    check('stream after moveTo', throws(fn() => $doc->getStream(), RuntimeException::class));
    unlink($moved);

    $again = new Kuman\Request("POST /u HTTP/1.1\r\nHost: t\r\nContent-Type: multipart/form-data; boundary=XX\r\nContent-Length: " . strlen($form) . "\r\n\r\n$form");
    $again->getUploadedFiles();
    $path = $again->file('doc')['tmp_name'];
    unset($again);
    check('the request deletes its upload', is_file($path), false);
}

function controllers(): void
{
    check('controller method', http('GET', '/controller/users/7')['body'], '{"id":"7","calls":1}');
    check('controller is created per request', http('GET', '/controller/users/7')['body'], '{"id":"7","calls":1}');
    check('static route before the dynamic one', http('GET', '/controller/users/mine')['body'], 'mine');
    $created = http('POST', '/controller/users', ['Content-Type' => 'application/json'], '{"name":"ana"}');
    check('controller returns a response', [$created['status'], $created['body']], [201, '{"created":"ana"}']);
    check('invokable controller', http('GET', '/controller/health')['body'], '{"ok":true}');
    check('an instance keeps its state', [
        http('GET', '/controller/instance/1')['body'],
        http('GET', '/controller/instance/1')['body'],
    ], ['{"id":"1","calls":1}', '{"id":"1","calls":2}']);
    check('controller as route middleware stops', http('GET', '/controller/gated/3')['status'], 403);
    check('controller as route middleware passes', http('GET', '/controller/gated/3', ['X-Pass' => 'yes'])['body'], '{"id":"3","calls":1}');

    $grouped = http('GET', '/controller/admin/users/5');
    check('controller in a group', $grouped['body'], '{"id":"5","calls":1}');
    check('controller in a group gets its middleware', hasHeader($grouped, 'X-Group: yes'));
}

function slimHandlers(): void
{
    $hello = http('GET', '/slim/hello/Ana%20B');
    check('slim handler', [$hello['status'], $hello['body'], hasHeader($hello, 'X-Method: GET')], [201, 'Hello, Ana B', true]);
    check('slim args take both syntaxes', http('GET', '/slim/mixed/x/y')['body'], '{"a":"x","b":"y"}');
    check('variadic handler gets all three', http('GET', '/slim/all/9')['body'], '{"count":3,"second":true,"third":{"id":"9"}}');
    check('one parameter handler is unchanged', http('GET', '/slim/one/4')['body'], '{"id":"4"}');
    $profile = http('GET', '/slim/controller/8');
    check('slim controller', [$profile['status'], $profile['body']], [202, 'profile 8']);
    check('slim handler behind route middleware', hasHeader(http('GET', '/slim/gated/2', ['X-Pass' => 'yes']), 'X-Id: 2'));
    check('slim handler is not reached when middleware stops', http('GET', '/slim/gated/2')['status'], 403);
}

function controllerErrors(): void
{
    require_once dirname(__DIR__) . '/tests/fixture/Controllers.php';
    $define = fn(mixed $handler) => fn() => (new Kuman\App())->get('/x', $handler);
    check('unknown class', throws($define([Fixture\Missing::class, 'run']), InvalidArgumentException::class));
    check('unknown method', throws($define([Fixture\UserController::class, 'nothing']), InvalidArgumentException::class));
    check('private method', throws($define([Fixture\UserController::class, 'hidden']), InvalidArgumentException::class));
    check('class without __invoke', throws($define(Fixture\UserController::class), InvalidArgumentException::class));
    check('not a handler', throws($define(['nothing']), InvalidArgumentException::class));
    check('valid controller', throws($define([Fixture\UserController::class, 'show'])), false);
    check('sse takes a controller too', throws(fn() => (new Kuman\App())->sse('/s', Fixture\HealthController::class)), false);
}

function psr7Http(): void
{
    $response = http('POST', '/psr/info?a=1&b[]=2', ['Content-Type' => 'application/json', 'X-Agent' => 'curl', 'Cookie' => 's=1'], '{"k":[1,2]}');
    $info = json_decode($response['body'], true);
    check('psr method', $info['method'], 'POST');
    check('psr uri', $info['uri'], 'http://test/psr/info?a=1&b%5B%5D=2');
    check('psr target', $info['target'], '/psr/info?a=1&b[]=2');
    check('psr query', $info['query'], ['a' => '1', 'b' => ['2']]);
    check('psr parsed body', $info['parsed'], ['k' => [1, 2]]);
    check('psr body', $info['body'], '{"k":[1,2]}');
    check('psr header', [$info['agent'], $info['agents'], $info['hasHost']], ['curl', ['curl'], true]);
    check('psr cookies', $info['cookies'], ['s' => '1']);
    check('psr attribute from middleware', $info['who'], 'middleware');
    check('psr attribute default', $info['missing'], 'fallback');
    check('psr server params', $info['remote'], '127.0.0.1');
    check('psr version', $info['version'], '1.1');

    $rewritten = json_decode(http('GET', '/psr-old/anything?x=1')['body'], true);
    check('middleware can rewrite the uri', [$rewritten['path'], $rewritten['query'], $rewritten['uri']], ['/psr/info', ['moved' => '1'], 'http://test/psr/info?moved=1']);

    $built = http('GET', '/psr/response');
    check('psr response status line', str_starts_with($built['head'], 'HTTP/1.1 201 Made'));
    check('psr response body', $built['body'], 'from a stream');
    check('psr response added header', hasHeader($built, 'X-Multi: a, b'));
    check('psr response content type', hasHeader($built, 'Content-Type: text/plain'));
    check('psr response cookies', preg_match_all('/\r\nSet-Cookie: (?:one=1|two=2; Max-Age=60; Path=\/; HttpOnly)(?=\r\n)/', $built['head']), 2);

    $foreign = http('GET', '/psr/foreign');
    check('response from another library', [$foreign['status'], $foreign['body'], hasHeader($foreign, 'X-Foreign: yes')], [202, 'foreign', true]);
    check('response from a file stream', http('GET', '/psr/streamed')['body'], "static hello\n");

    $invalid = http('GET', '/psr/invalid');
    check('invalid header fails the request', $invalid['status'], 500);
    check('invalid header is not sent', stripos($invalid['head'], 'injected'), false);

    $static = http('GET', '/hello.txt');
    check('middleware reaches a file response', hasHeader($static, 'X-Global: yes'));
    check('file response keeps its body', $static['body'], "static hello\n");
    $slice = http('GET', '/hello.txt', ['Range' => 'bytes=7-11']);
    check('file response keeps its range', [$slice['status'], $slice['body'], hasHeader($slice, 'X-Global: yes')], [206, 'hello', true]);

    $form = "--XX\r\nContent-Disposition: form-data; name=\"doc\"; filename=\"a.txt\"\r\nContent-Type: text/plain\r\n\r\nfile body\r\n--XX--\r\n";
    $upload = http('POST', '/psr/upload', ['Content-Type' => 'multipart/form-data; boundary=XX'], $form);
    check('uploaded file over http', json_decode($upload['body'], true), [
        'name' => 'a.txt',
        'type' => 'text/plain',
        'size' => 9,
        'content' => 'file body',
        'who' => 'middleware',
        'saved' => 'file body',
    ]);
}

function jwtToken(string $header, string $payload, string $signature = ''): string
{
    $encode = static fn(string $data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    return $encode($header) . '.' . $encode($payload) . '.' . $encode($signature);
}

function authObjects(): void
{
    $secret = str_repeat('s', 32);
    $jwt = new Kuman\Auth\Jwt($secret);
    $token = $jwt->issue(['sub' => 7]);
    $claims = $jwt->verify($token);
    check('jwt round trip', $claims['sub'] ?? null, 7);
    check('jwt adds iat and exp', ($claims['exp'] ?? 0) - ($claims['iat'] ?? 0), 3600);
    check('jwt other secret', (new Kuman\Auth\Jwt(str_repeat('t', 32)))->verify($token), null);
    $forever = new Kuman\Auth\Jwt($secret, ttl: null);
    check('jwt ttl null has no exp', array_keys($forever->verify($forever->issue()) ?? []), ['iat']);

    [$head, $body, $signature] = explode('.', $token);
    $other = rtrim(strtr(base64_encode('{"sub":8,"exp":9999999999}'), '+/', '-_'), '=');
    check('jwt changed payload', $jwt->verify("$head.$other.$signature"), null);
    check('jwt missing part', $jwt->verify("$head.$body"), null);
    check('jwt extra part', $jwt->verify("$token.x"), null);
    check('jwt not base64', $jwt->verify("$head.$body.@@@"), null);
    check('jwt empty', $jwt->verify(''), null);
    check('jwt empty signature', $jwt->verify("$head.$body."), null);
    check('jwt scalar header', $jwt->verify(jwtToken('1', '{}', 'x')), null);

    check('jwt alg none', $jwt->verify(jwtToken('{"alg":"none"}', '{"sub":7}')), null);
    check('jwt alg none in capitals', $jwt->verify(jwtToken('{"alg":"NONE"}', '{"sub":7}')), null);
    $sign = static fn(string $header, string $payload, string $key) => jwtToken($header, $payload, hash_hmac(
        'sha256',
        rtrim(strtr(base64_encode($header), '+/', '-_'), '=') . '.' . rtrim(strtr(base64_encode($payload), '+/', '-_'), '='),
        $key,
        true
    ));
    check('jwt alg it does not use', (new Kuman\Auth\Jwt(str_repeat('s', 64), 'HS512'))->verify($token), null);
    check('jwt signed by hand verifies', $jwt->verify($sign('{"alg":"HS256"}', '{"sub":7}', $secret)), ['sub' => 7]);
    check('jwt header names another algorithm', $jwt->verify($sign('{"alg":"HS512"}', '{"sub":7}', $secret)), null);
    check('jwt crit', $jwt->verify($sign('{"alg":"HS256","crit":["x"]}', '{"sub":7}', $secret)), null);
    check('jwt payload not an object', $jwt->verify($sign('{"alg":"HS256"}', '7', $secret)), null);
    check('jwt exp as text', $jwt->verify($sign('{"alg":"HS256"}', '{"exp":"9999999999"}', $secret)), null);
    check('jwt nbf as text', $jwt->verify($sign('{"alg":"HS256"}', '{"nbf":"0"}', $secret)), null);

    check('jwt expired', $jwt->verify($jwt->issue(['exp' => time() - 1])), null);
    check('jwt expires at exp', $jwt->verify($jwt->issue(['exp' => time()])), null);
    check('jwt not yet valid', $jwt->verify($jwt->issue(['nbf' => time() + 60])), null);
    $lenient = new Kuman\Auth\Jwt($secret, leeway: 120);
    check('jwt leeway on exp', $lenient->verify($lenient->issue(['exp' => time() - 60])) !== null);
    check('jwt leeway on nbf', $lenient->verify($lenient->issue(['nbf' => time() + 60])) !== null);

    $strict = new Kuman\Auth\Jwt($secret, issuer: 'me', audience: 'app');
    check('jwt issuer and audience', ($strict->verify($strict->issue()) ?? [])['iss'] ?? null, 'me');
    check('jwt missing issuer', $strict->verify($jwt->issue(['aud' => 'app'])), null);
    check('jwt other issuer', $strict->verify($jwt->issue(['iss' => 'you', 'aud' => 'app'])), null);
    check('jwt missing audience', $strict->verify($jwt->issue(['iss' => 'me'])), null);
    check('jwt audience in a list', $strict->verify($jwt->issue(['iss' => 'me', 'aud' => ['x', 'app']])) !== null);
    check('jwt other audience', $strict->verify($jwt->issue(['iss' => 'me', 'aud' => ['x']])), null);

    check('jwt short secret', throws(fn() => new Kuman\Auth\Jwt('short'), InvalidArgumentException::class));
    check('jwt secret too short for HS512', throws(fn() => new Kuman\Auth\Jwt($secret, 'HS512'), InvalidArgumentException::class));
    check('jwt unknown algorithm', throws(fn() => new Kuman\Auth\Jwt($secret, 'none'), InvalidArgumentException::class));

    // RFC 7515 appendix A: tokens signed by someone else's code. They expired in 2011.
    $old = 2000000000;
    $rfcSecret = base64_decode(strtr('AyM1SysPpbyDfgZld3umj1qzKObwVMkoqQ-EstJQLr_T-1qS0gZH75aKtMN3Yj0iPS4hcgUuTwjAzZr1Z9CAow', '-_', '+/'));
    $rfcClaims = 'eyJpc3MiOiJqb2UiLA0KICJleHAiOjEzMDA4MTkzODAsDQogImh0dHA6Ly9leGFtcGxlLmNvbS9pc19yb290Ijp0cnVlfQ';
    check('jwt rfc 7515 HS256', (new Kuman\Auth\Jwt($rfcSecret, leeway: $old))->verify("eyJ0eXAiOiJKV1QiLA0KICJhbGciOiJIUzI1NiJ9.$rfcClaims.dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk")['iss'] ?? null, 'joe');
    $point = base64_decode(strtr('f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU', '-_', '+/')) . base64_decode(strtr('x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0', '-_', '+/'));
    $rfcPublic = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $point), 64, "\n") . "-----END PUBLIC KEY-----\n";
    check('jwt rfc 7515 ES256', (new Kuman\Auth\Jwt($rfcPublic, 'ES256', leeway: $old))->verify("eyJhbGciOiJFUzI1NiJ9.$rfcClaims.DtEhU3ljbEg8L38VWAfUAqOyKAM6-Xx-F4GawxaepmXFCgfTjDxw5djxLa8ISlSApmWQxfKTUJqPP3-Kg6NU1Q")['iss'] ?? null, 'joe');

    $export = static function (array $options): array {
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $private);
        return [$private, openssl_pkey_get_details($key)['key']];
    };
    [$rsaPrivate, $rsaPublic] = $export(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    foreach (['RS256', 'RS384', 'RS512'] as $algorithm) {
        $issuer = new Kuman\Auth\Jwt($rsaPrivate, $algorithm);
        check("jwt $algorithm", (new Kuman\Auth\Jwt($rsaPublic, $algorithm))->verify($issuer->issue(['sub' => 1]))['sub'] ?? null, 1);
    }
    $rsa = new Kuman\Auth\Jwt($rsaPublic, 'RS256');
    check('jwt a public key cannot issue', throws(fn() => $rsa->issue(), RuntimeException::class));
    check('jwt private key verifies too', (new Kuman\Auth\Jwt($rsaPrivate, 'RS256'))->verify((new Kuman\Auth\Jwt($rsaPrivate, 'RS256'))->issue()) !== null);
    check('jwt the public key is not an HMAC secret', $rsa->verify($sign('{"alg":"HS256"}', '{"sub":7}', $rsaPublic)), null);
    check('jwt not a key', throws(fn() => new Kuman\Auth\Jwt('not a key', 'RS256'), InvalidArgumentException::class));
    [$weak] = $export(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
    check('jwt weak rsa key', throws(fn() => new Kuman\Auth\Jwt($weak, 'RS256'), InvalidArgumentException::class));

    foreach (['ES256' => 'prime256v1', 'ES384' => 'secp384r1'] as $algorithm => $curve) {
        [$private, $public] = $export(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
        $issuer = new Kuman\Auth\Jwt($private, $algorithm);
        $verifier = new Kuman\Auth\Jwt($public, $algorithm);
        // Enough signatures to meet integers with leading zero bytes, which DER drops.
        $good = 0;
        for ($i = 0; $i < 500; $i++) {
            $good += $verifier->verify($issuer->issue(['n' => $i])) === null ? 0 : 1;
        }
        check("jwt $algorithm", $good, 500);
        [$head, $body, $signature] = explode('.', $issuer->issue());
        check("jwt $algorithm short signature", $verifier->verify("$head.$body." . substr($signature, 0, -4)), null);
    }
    check('jwt curve of another algorithm', throws(fn() => new Kuman\Auth\Jwt($export(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1'])[0], 'ES256'), InvalidArgumentException::class));
    check('jwt rsa key for ecdsa', throws(fn() => new Kuman\Auth\Jwt($rsaPrivate, 'ES256'), InvalidArgumentException::class));

    $hash = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);
    check('password verifies', Kuman\Auth::verify('secret', $hash));
    check('wrong password', Kuman\Auth::verify('nope', $hash), false);
    check('array as password', Kuman\Auth::verify(['secret'], $hash), false);
    check('no account', Kuman\Auth::verify('secret', null), false);
    check('empty password without account', Kuman\Auth::verify('', null), false);
    check('empty hash', Kuman\Auth::verify('secret', ''), false);
    check('hash round trip', password_verify('x', Kuman\Auth::hash('x')));
    check('token size', strlen(Kuman\Auth::token()), 64);
    check('tokens differ', Kuman\Auth::token() === Kuman\Auth::token(), false);

    check('plain password in a user map', throws(fn() => Kuman\Auth::basic(['ana' => 'secret']), InvalidArgumentException::class));
    check('short fixed token', throws(fn() => Kuman\Auth::bearer(['short' => 'ana']), InvalidArgumentException::class));
    check('token key from an unset variable', throws(fn() => Kuman\Auth::apiKey([false => 'ana']), InvalidArgumentException::class));
    check('any without guards', throws(fn() => Kuman\Auth::any(), InvalidArgumentException::class));
}

function basicHeader(string $credentials): array
{
    return ['Authorization' => 'Basic ' . base64_encode($credentials)];
}

function sessionCookie(array $response): ?string
{
    return preg_match('/\r\nSet-Cookie: PHPSID=([^;\r]+)/', $response['head'], $match) ? $match[1] : null;
}

function authentication(): void
{
    $token = 'fixture-token-0123456789';
    $bearer = ['Authorization' => "Bearer $token"];

    $denied = http('GET', '/auth/basic');
    check('basic without credentials', [$denied['status'], $denied['body']], [401, '{"error":"unauthorized"}']);
    check('basic challenge quotes the realm', hasHeader($denied, 'WWW-Authenticate: Basic realm="Say \\"hi\\"", charset="UTF-8"'));
    check('basic password with a colon', json_decode(http('GET', '/auth/basic', basicHeader('ana:pa:ss'))['body'], true), ['user' => 'ana', 'current' => 'ana']);
    check('basic scheme ignores case', http('GET', '/auth/basic', ['Authorization' => 'bAsIc ' . base64_encode('ana:pa:ss')])['status'], 200);
    check('basic wrong password', http('GET', '/auth/basic', basicHeader('ana:nope'))['status'], 401);
    check('basic unknown user', http('GET', '/auth/basic', basicHeader('bob:pa:ss'))['status'], 401);
    check('basic without a colon', http('GET', '/auth/basic', basicHeader('anapass'))['status'], 401);
    check('basic control character', http('GET', '/auth/basic', basicHeader("ana:pa:ss\0"))['status'], 401);
    check('basic bad base64', http('GET', '/auth/basic', ['Authorization' => 'Basic @@@'])['status'], 401);
    check('basic other scheme', http('GET', '/auth/basic', $bearer)['status'], 401);
    check('basic callback returning true gives the username', json_decode(http('GET', '/auth/basic-callback', basicHeader('ana:x'))['body'], true)['user'] ?? null, 'ana');
    check('basic callback returning false', http('GET', '/auth/basic-callback', basicHeader('ana:y'))['status'], 401);

    check('bearer', json_decode(http('GET', '/auth/bearer', $bearer)['body'], true)['user'] ?? null, 'ana');
    $missing = http('GET', '/auth/bearer');
    check('bearer without a token has no error code', hasHeader($missing, 'WWW-Authenticate: Bearer realm="api"' . "\r\n"));
    $wrong = http('GET', '/auth/bearer', ['Authorization' => 'Bearer wrong-token-012345678']);
    check('bearer wrong token', [$wrong['status'], hasHeader($wrong, 'WWW-Authenticate: Bearer realm="api", error="invalid_token"')], [401, true]);
    check('bearer two tokens', http('GET', '/auth/bearer', ['Authorization' => "Bearer $token, Bearer $token"])['status'], 401);
    check('bearer in the query', http('GET', "/auth/bearer?access_token=$token")['status'], 200);
    check('bearer query as an array', http('GET', "/auth/bearer?access_token[]=$token")['status'], 401);
    check('bearer query is off by default', http('GET', "/auth/shared?access_token=$token")['status'], 401);
    check('bearer token that PHP keeps as an integer key', http('GET', '/auth/numeric', ['Authorization' => 'Bearer 1234567890123456'])['status'], 200);
    check('bearer callback result', json_decode(http('GET', '/auth/bearer-callback', ['Authorization' => 'Bearer good'])['body'], true)['user'] ?? null, ['id' => 1]);
    check('bearer callback returning false', http('GET', '/auth/bearer-callback', ['Authorization' => 'Bearer bad'])['status'], 401);

    check('api key', json_decode(http('GET', '/auth/key', ['X-API-Key' => $token])['body'], true)['user'] ?? null, 'ci');
    check('api key challenge', hasHeader(http('GET', '/auth/key'), 'WWW-Authenticate: ApiKey realm="api"'));
    check('api key empty', http('GET', '/auth/key', ['X-API-Key' => ''])['status'], 401);
    check('api key wrong', http('GET', '/auth/key', ['X-API-Key' => 'wrong-token-012345678'])['status'], 401);
    check('api key is not a bearer token', http('GET', '/auth/key', $bearer)['status'], 401);
    check('api key in the query is off by default', http('GET', "/auth/key?key=$token")['status'], 401);
    check('api key in a named header', http('GET', '/auth/key-named', ['X-Other' => $token])['status'], 200);
    check('api key in a named query', http('GET', "/auth/key-named?key=$token")['status'], 200);

    $issued = json_decode(http('POST', '/auth/jwt/login')['body'], true)['token'];
    $claims = json_decode(http('GET', '/auth/jwt', ['Authorization' => "Bearer $issued"])['body'], true)['user'];
    check('jwt guard gives the claims', [$claims['sub'], $claims['iss']], ['ana', 'kuman']);
    check('jwt guard resolves the user', json_decode(http('GET', '/auth/jwt-resolved', ['Authorization' => "Bearer $issued"])['body'], true)['user'] ?? null, 'ANA');
    $broken = http('GET', '/auth/jwt', ['Authorization' => 'Bearer ' . substr($issued, 0, -2) . 'xx']);
    check('jwt guard refuses a changed token', [$broken['status'], str_contains($broken['head'], 'error="invalid_token"')], [401, true]);
    check('jwt guard refuses alg none', http('GET', '/auth/jwt', ['Authorization' => 'Bearer ' . jwtToken('{"alg":"none"}', '{"sub":"ana","iss":"kuman"}')])['status'], 401);

    $anonymous = http('GET', '/auth/session');
    check('session guard without a login', $anonymous['status'], 401);
    check('session guard starts no session', hasHeader($anonymous, 'Set-Cookie'), false);
    $redirect = http('GET', '/auth/session-page');
    check('session guard redirects', [$redirect['status'], hasHeader($redirect, 'Location: /login')], [302, true]);
    check('session guard with an invalid cookie', hasHeader(http('GET', '/auth/session', ['Cookie' => 'PHPSID=x']), 'Set-Cookie'), false);

    $planted = 'plantedsession0123456789';
    $login = http('POST', '/auth/login', ['Content-Type' => 'application/json', 'Cookie' => "PHPSID=$planted"], '{"id":"7"}');
    $sid = sessionCookie($login);
    check('login is stored before the response', json_decode($login['body'], true), ['stored' => true]);
    check('login replaces a planted session id', $sid !== null && $sid !== $planted);
    check('login keeps the cookies of the response', hasHeader($login, 'Set-Cookie: theme=dark'));
    check('session cookie is HttpOnly', preg_match('/\r\nSet-Cookie: PHPSID=[^\r]*; HttpOnly/', $login['head']), 1);
    check('session cookie is SameSite=Lax', preg_match('/\r\nSet-Cookie: PHPSID=[^\r]*; SameSite=Lax/', $login['head']), 1);
    check('session directory is private', fileperms($GLOBALS['tmp'] . '/runtime/sessions') & 0777, 0700);

    $cookie = ['Cookie' => "PHPSID=$sid"];
    check('session guard', json_decode(http('GET', '/auth/session', $cookie)['body'], true), ['user' => '7', 'current' => '7']);
    check('planted session id stays anonymous', http('GET', '/auth/session', ['Cookie' => "PHPSID=$planted"])['status'], 401);
    check('session resolver', json_decode(http('GET', '/auth/session-resolved', $cookie)['body'], true)['user'] ?? null, 'user 7');
    check('session on any guard', json_decode(http('GET', '/auth/any', $cookie)['body'], true)['user'] ?? null, '7');
    $gone = sessionCookie(http('POST', '/auth/login', ['Content-Type' => 'application/json'], '{"id":"gone"}'));
    check('session resolver can refuse', http('GET', '/auth/session-resolved', ['Cookie' => "PHPSID=$gone"])['status'], 401);

    $file = $GLOBALS['tmp'] . "/runtime/sessions/session_$sid";
    touch($file, time() - 100);
    http('GET', '/auth/session', $cookie);
    check('activity keeps a session alive', waitFor(function () use ($file) {
        clearstatcache();
        return filemtime($file) > time() - 50;
    }));

    $again = sessionCookie(http('POST', '/auth/login', ['Content-Type' => 'application/json', 'Cookie' => "PHPSID=$sid"], '{"id":"8"}'));
    check('a second login ends the first session', http('GET', '/auth/session', $cookie)['status'], 401);
    $cookie = ['Cookie' => "PHPSID=$again"];
    check('the second login is in the new session', json_decode(http('GET', '/auth/session', $cookie)['body'], true)['user'] ?? null, '8');

    $logout = http('POST', '/auth/logout', $cookie);
    check('logout expires the cookie', hasHeader($logout, 'Set-Cookie: PHPSID=; Max-Age=0; Path=/'));
    check('logout answers with the given response', $logout['body'], '{"out":true}');
    check('logout ends the session', http('GET', '/auth/session', $cookie)['status'], 401);
    check('logout without a session', http('POST', '/auth/logout')['status'], 200);

    check('any second guard', json_decode(http('GET', '/auth/any', $bearer)['body'], true)['user'] ?? null, 'token user');
    check('any refused', http('GET', '/auth/any')['status'], 401);
    check('optional without credentials', json_decode(http('GET', '/auth/open')['body'], true), ['user' => null, 'current' => null]);
    check('optional with credentials', json_decode(http('GET', '/auth/open', $bearer)['body'], true)['user'] ?? null, 'ana');
    check('optional() leaves the shared guard strict', http('GET', '/auth/shared')['status'], 401);
    $custom = http('GET', '/auth/custom');
    check('onDeny', [$custom['status'], $custom['body'], hasHeader($custom, 'WWW-Authenticate: Bearer')], [401, 'custom', true]);
    check('onDeny leaves the shared guard alone', http('GET', '/auth/shared')['body'], '{"error":"unauthorized"}');

    check('guard on a prefix refuses', http('GET', '/auth/zone/page')['status'], 401);
    check('guard on a prefix passes', http('GET', '/auth/zone/page', $bearer)['status'], 200);
    check('guard on a group refuses', http('GET', '/auth/team/page')['status'], 401);
    check('guard on a group passes', http('GET', '/auth/team/page', $bearer)['status'], 200);

    [$socket, $head] = openSocket('/auth/socket');
    check('guard refuses a websocket', substr($head, 9, 3), '401');
    fclose($socket);
    [$socket, $head] = openSocket("/auth/socket?token=$token");
    check('guard passes a websocket', substr($head, 9, 3), '101');
    check('websocket open sees the user', readFrame($socket), 'hi ana');
    fclose($socket);

    check('guard refuses a stream', http('GET', '/auth/stream')['status'], 401);
    $socket = connect();
    fwrite($socket, "GET /auth/stream?token=$token HTTP/1.1\r\nHost: test\r\n\r\n");
    readHead($socket);
    check('stream sees the user', stream_get_contents($socket), "data: ana\n\n");
    fclose($socket);
}

function suite(string $title, string $loop, array $tests): void
{
    global $failures;
    echo "$title\n";
    prepare();
    $before = $failures;
    kuman('start -d', ['PORT' => PORT, 'KUMAN_LOOP' => $loop]);
    try {
        if (!waitFor(fn() => @fsockopen('127.0.0.1', PORT) !== false)) {
            throw new RuntimeException('server did not start');
        }
        foreach ($tests as $test) {
            $test();
        }
    } finally {
        kuman('stop');
        waitFor(fn() => @fsockopen('127.0.0.1', PORT) === false);
    }
    echo $failures === $before ? "  ok\n" : "  failed\n";
}

$common = ['routing', 'middleware', 'controllers', 'slimHandlers', 'psr7Http', 'authentication', 'connectionHandling', 'errors', 'statics', 'realtime', 'websockets', 'reloading'];

try {
    echo "psr-7 objects\n";
    psr7Objects();
    echo "controllers\n";
    controllerErrors();
    echo "authentication objects\n";
    authObjects();
    suite('default event loop', '', $common);
    suite('fiber event loop', 'fiber', [...$common, 'concurrency']);
    echo "watcher\n";
    prepare();
    watching();
    echo "configuration\n";
    prepare();
    configuration();
} catch (Throwable $e) {
    $failures++;
    echo "  ABORTED $e\n";
}

exec('rm -rf ' . escapeshellarg($tmp));
echo $failures === 0 ? "\n$passes checks passed\n" : "\n$failures failed, $passes passed\n";
exit($failures === 0 ? 0 : 1);
