<?php

declare(strict_types=1);

namespace Kuman;

use ArrayObject;
use Closure;
use FastRoute\Dispatcher;
use stdClass;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Coroutine\Context;
use Workerman\Protocols\Http\Response as BaseResponse;
use Workerman\Timer;
use Workerman\Worker;

class App
{
    private const NOT_SERVED = ['php', 'phar', 'phtml'];

    private Router $router;

    /** @var array<int, callable> */
    private array $middleware = [];

    /** @var array<int, array{0: float, 1: callable}> */
    private array $schedule = [];

    /** @var array<int, callable> Middleware of the group being defined. */
    private array $scope = [];

    private string $prefix = '';

    private int $depth = 0;

    /** @var ?callable */
    private $errorHandler = null;

    /** @var ?callable */
    private $notFoundHandler = null;

    private Closure $kernel;

    public function __construct(private readonly string $publicPath = '', private readonly bool $debug = false)
    {
        $this->router = new Router();
    }

    public function get(string $path, callable ...$handlers): static
    {
        return $this->route(['GET'], $path, ...$handlers);
    }

    public function post(string $path, callable ...$handlers): static
    {
        return $this->route(['POST'], $path, ...$handlers);
    }

    public function put(string $path, callable ...$handlers): static
    {
        return $this->route(['PUT'], $path, ...$handlers);
    }

    public function patch(string $path, callable ...$handlers): static
    {
        return $this->route(['PATCH'], $path, ...$handlers);
    }

    public function delete(string $path, callable ...$handlers): static
    {
        return $this->route(['DELETE'], $path, ...$handlers);
    }

    public function options(string $path, callable ...$handlers): static
    {
        return $this->route(['OPTIONS'], $path, ...$handlers);
    }

    public function all(string $path, callable ...$handlers): static
    {
        return $this->route(['*'], $path, ...$handlers);
    }

    /**
     * The last handler answers the request, the ones before it are route middleware.
     *
     * @param array<int, string> $methods
     */
    public function route(array $methods, string $path, callable ...$handlers): static
    {
        $handler = array_pop($handlers);
        $this->router->add($methods, $this->prefix . $path, self::pipe([...$this->scope, ...$handlers], $handler));
        return $this;
    }

    /**
     * Global middleware, or middleware limited to a path prefix: use('/admin', $auth).
     * Inside a group it applies to the routes defined after it in that group.
     */
    public function use(string|callable $prefix, callable ...$middleware): static
    {
        if (is_string($prefix) && str_starts_with($prefix, '/')) {
            $prefix = rtrim($this->prefix . $prefix, '/');
            $middleware = array_map(static fn(callable $next) => self::under($prefix, $next), $middleware);
        } else {
            array_unshift($middleware, $prefix);
        }

        if ($this->depth > 0) {
            array_push($this->scope, ...$middleware);
        } else {
            array_push($this->middleware, ...$middleware);
        }
        return $this;
    }

    /**
     * group('/api', $auth, function (App $api) { ... }): the last callable defines the routes.
     */
    public function group(string $prefix, callable ...$handlers): static
    {
        $define = array_pop($handlers);
        $saved = [$this->prefix, $this->scope];

        $this->prefix .= '/' . trim($prefix, '/');
        array_push($this->scope, ...$handlers);
        $this->depth++;
        try {
            $define($this);
        } finally {
            $this->depth--;
            [$this->prefix, $this->scope] = $saved;
        }
        return $this;
    }

    /**
     * WebSocket on the HTTP port. The last argument holds the open, message and close
     * callbacks, the ones before it are route middleware.
     *
     * @param callable|array<string, callable> ...$handlers
     */
    public function ws(string $path, callable|array ...$handlers): static
    {
        $on = array_pop($handlers);
        return $this->route(['GET'], $path, ...[...$handlers, function (Request $request) use ($on) {
            if (strcasecmp((string)$request->header('upgrade'), 'websocket') !== 0) {
                return Response::text('Upgrade Required', 426);
            }
            $on += ['open' => static fn() => null, 'message' => static fn() => null, 'close' => static fn() => null];

            $connection = $request->connection;
            $connection->protocol = WebSocket::class;
            $connection->onWebSocketConnected = $this->socketCallback(
                static fn(TcpConnection $connection) => $on['open']($connection, $request)
            );
            $connection->onMessage = $this->socketCallback(static function (TcpConnection $connection, mixed $frame) use ($on) {
                // A buffer matching a cached HTTP request reaches this callback undecoded.
                if (!$frame instanceof stdClass) {
                    $connection->close();
                    return;
                }
                $on['message']($connection, $frame->data);
            });
            $connection->onClose = $this->socketCallback(
                static fn(TcpConnection $connection) => $on['close']($connection)
            );
            WebSocket::input($request->rawBuffer(), $connection);
            return null;
        }]);
    }

    /**
     * Server-Sent Events: the last handler receives the request and a Kuman\Sse stream.
     */
    public function sse(string $path, callable ...$handlers): static
    {
        $handler = array_pop($handlers);
        return $this->route(['GET'], $path, ...[...$handlers, function (Request $request) use ($handler) {
            $connection = $request->connection;
            $connection->send(new BaseResponse(200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]));

            $stream = new Sse($connection, $this);
            try {
                $handler($request, $stream);
            } catch (Throwable $e) {
                $this->report($e);
                $stream->close();
            }
            return null;
        }]);
    }

    /**
     * Run a callback repeatedly, on the first worker only.
     */
    public function every(float $seconds, callable $callback): static
    {
        $this->schedule[] = [$seconds, $callback];
        return $this;
    }

    /**
     * Run a callback once, in the current worker.
     */
    public function after(float $seconds, callable $callback): int
    {
        return Timer::add($seconds, $this->guard($callback), [], false);
    }

    /**
     * Timers and connection callbacks run outside any request, and an exception
     * there would end the worker. Wrap them in this to log it instead.
     */
    public function guard(callable $callback): Closure
    {
        return function (mixed ...$args) use ($callback): mixed {
            try {
                return $callback(...$args);
            } catch (Throwable $e) {
                $this->report($e);
                return null;
            }
        };
    }

    /**
     * Render uncaught exceptions: fn(Throwable $e, Request $request) => response.
     */
    public function onError(callable $handler): static
    {
        $this->errorHandler = $handler;
        return $this;
    }

    /**
     * Render missing routes: fn(Request $request) => response.
     */
    public function notFound(callable $handler): static
    {
        $this->notFoundHandler = $handler;
        return $this;
    }

    public function start(Worker $worker): void
    {
        $this->router->compile();
        $this->kernel = self::pipe($this->middleware, $this->dispatch(...));
        $worker->onMessage = $this->onMessage(...);

        if ($worker->id === 0) {
            foreach ($this->schedule as [$seconds, $callback]) {
                Timer::add($seconds, $this->guard($callback));
            }
        }
    }

    private function onMessage(TcpConnection $connection, Request $request): void
    {
        // Workerman hands identical requests the same cached object. On the fiber
        // loop a suspended handler would see it pointed at another connection.
        $request = clone $request;
        $request->connection = $connection;

        Context::reset(new ArrayObject([Request::class => $request]));
        try {
            $response = ($this->kernel)($request);
            if ($response !== null) {
                $response = Response::from($response);
            }
        } catch (Throwable $e) {
            $response = $this->render($e, $request);
        }
        Context::destroy();

        // Null means the handler keeps the connection: WebSocket, SSE or a later respond().
        if ($response !== null) {
            $request->respond($response);
        }
    }

    private function dispatch(Request $request): mixed
    {
        $path = $request->path();
        if (self::isUnsafe($path)) {
            return Response::text('Bad Request', 400);
        }

        $match = $this->router->match($request->method(), $path);
        if ($match[0] === Dispatcher::FOUND) {
            $request->params = $match[2];
            return $match[1]($request);
        }

        $file = $this->staticFile($request);
        if ($file !== null) {
            return Response::file($file, $request);
        }
        if ($match[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            return Response::text('Method Not Allowed', 405)->withHeader('Allow', implode(', ', $match[1]));
        }
        return $this->notFoundHandler === null
            ? Response::text('Not Found', 404)
            : ($this->notFoundHandler)($request);
    }

    private function staticFile(Request $request): ?string
    {
        $method = $request->method();
        if ($this->publicPath === '' || ($method !== 'GET' && $method !== 'HEAD')) {
            return null;
        }

        $path = rawurldecode($request->path());
        if (self::isUnsafe($path) || preg_match('~/\.(?!well-known/)~', $path)) {
            return null;
        }

        clearstatcache(true, $this->publicPath . $path);
        $file = realpath($this->publicPath . $path);
        if ($file === false || !is_file($file) || !str_starts_with($file, "$this->publicPath/")) {
            return null;
        }
        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::NOT_SERVED, true) ? null : $file;
    }

    private function render(Throwable $e, Request $request): Response
    {
        $this->report($e);
        if ($this->errorHandler !== null) {
            try {
                return Response::from(($this->errorHandler)($e, $request));
            } catch (Throwable $handlerError) {
                $this->report($handlerError);
            }
        }
        return Response::text($this->debug ? (string)$e : 'Internal Server Error', 500);
    }

    private function report(Throwable $e): void
    {
        Worker::log((string)$e);
    }

    /**
     * Like guard(), and drops the connection: its state is unknown after a failure.
     */
    private function socketCallback(callable $callback): Closure
    {
        return function (TcpConnection $connection, mixed ...$args) use ($callback): void {
            try {
                $callback($connection, ...$args);
            } catch (Throwable $e) {
                $this->report($e);
                $connection->close();
            }
        };
    }

    /**
     * Middleware receives $next returning a response, or null when the handler kept the connection.
     *
     * @param array<int, callable> $middleware
     */
    private static function pipe(array $middleware, callable $handler): Closure
    {
        if ($middleware === []) {
            return $handler(...);
        }

        $normalize = static fn(callable $step) => static function (Request $request) use ($step): ?Response {
            $response = $step($request);
            return $response === null ? null : Response::from($response);
        };

        return array_reduce(
            array_reverse($middleware),
            static fn(callable $next, callable $layer) => $normalize(static fn(Request $request) => $layer($request, $next)),
            $normalize($handler)
        );
    }

    private static function under(string $prefix, callable $middleware): Closure
    {
        return static function (Request $request, callable $next) use ($prefix, $middleware): mixed {
            // Static files are looked up by decoded path, so compare the same form.
            $path = preg_replace('~/+~', '/', rawurldecode($request->path()));
            $inside = $path === $prefix || str_starts_with($path, "$prefix/");
            return $inside ? $middleware($request, $next) : $next($request);
        };
    }

    private static function isUnsafe(string $path): bool
    {
        return $path === ''
            || $path[0] !== '/'
            || str_contains($path, '/../')
            || str_ends_with($path, '/..')
            || str_contains($path, '\\')
            || str_contains($path, "\0");
    }
}
