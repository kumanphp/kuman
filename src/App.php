<?php

declare(strict_types=1);

namespace Kuman;

use ArrayObject;
use Closure;
use FastRoute\Dispatcher;
use InvalidArgumentException;
use Kuman\Validation\Invalid;
use ReflectionMethod;
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

    private Resolver $resolver;

    /** @var array<int, Route> */
    private array $routes = [];

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

    /** @var ?callable */
    private $invalidHandler = null;

    private Closure $kernel;

    public function __construct(private readonly string $publicPath = '', private readonly bool $debug = false)
    {
        $this->router = new Router();
        $this->resolver = new Resolver();
    }

    public function get(string $path, callable|array|string ...$handlers): static
    {
        return $this->route(['GET'], $path, ...$handlers);
    }

    public function post(string $path, callable|array|string ...$handlers): static
    {
        return $this->route(['POST'], $path, ...$handlers);
    }

    public function put(string $path, callable|array|string ...$handlers): static
    {
        return $this->route(['PUT'], $path, ...$handlers);
    }

    public function patch(string $path, callable|array|string ...$handlers): static
    {
        return $this->route(['PATCH'], $path, ...$handlers);
    }

    public function delete(string $path, callable|array|string ...$handlers): static
    {
        return $this->route(['DELETE'], $path, ...$handlers);
    }

    public function options(string $path, callable|array|string ...$handlers): static
    {
        return $this->route(['OPTIONS'], $path, ...$handlers);
    }

    public function all(string $path, callable|array|string ...$handlers): static
    {
        return $this->route(['*'], $path, ...$handlers);
    }

    /**
     * The last handler answers the request, the ones before it are route middleware.
     * A handler is a callable, an invokable class name, or [Controller::class, 'method']:
     * the controller is created for each request. An answering handler that declares a
     * second parameter also receives a response to fill and the route parameters:
     * fn($request, $response, array $args).
     *
     * @param array<int, string> $methods
     */
    public function route(array $methods, string $path, callable|array|string ...$handlers): static
    {
        return $this->define($methods, $path, $handlers);
    }

    /**
     * An object handlers can ask for by type, one for the whole worker: a database
     * connection, a client. Create it here, in the closure of app.php, after the fork.
     */
    public function provide(string $class, object $service): static
    {
        $this->resolver->provide($class, $service);
        return $this;
    }

    /**
     * Made for each request that asks for $class, at most once, by a function whose own
     * parameters are injected: fn(Request $req, Db $db) => new Tenant(...).
     */
    public function scoped(string $class, callable|array|string $factory): static
    {
        $this->resolver->scoped($class, $factory);
        return $this;
    }

    /**
     * Serve an OpenAPI document and a page to browse it. Off unless called, because the
     * document lists every route: put a guard in $middleware outside development.
     *
     * @param ?string $ui 'scalar', 'swagger', 'redoc', or null to serve only the document.
     *     The pages load their scripts from a CDN.
     * @param array<int, callable> $middleware
     */
    public function enableDocs(
        string $path = '/docs',
        string $title = 'API',
        string $version = '1.0.0',
        ?string $ui = 'scalar',
        string $spec = '/openapi.json',
        array $middleware = [],
    ): static {
        if ($ui !== null && !isset(OpenApi::PAGES[$ui])) {
            throw new InvalidArgumentException("Unknown docs ui: $ui");
        }

        $document = null;
        $serve = function () use (&$document, $title, $version): Response {
            $document ??= json_encode(
                (new OpenApi($this->routes, $this->middleware, $title, $version))->document(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            return new Response(200, ['Content-Type' => 'application/json'], $document);
        };

        $specPath = '/' . trim($this->prefix . '/' . ltrim($spec, '/'), '/');
        $middleware = [...$this->scope, ...array_map(self::resolve(...), $middleware)];
        // First, because a later static route is refused behind a route like /:slug.
        array_unshift($this->routes, new Route(['GET'], $specPath, $middleware, $serve, $serve, false));
        if ($ui !== null) {
            $page = static fn() => Response::html(OpenApi::page($ui, $title, $specPath));
            array_unshift($this->routes, new Route(['GET'], $this->prefix . $path, $middleware, $page, $page, false));
        }
        return $this;
    }

    /**
     * @param array<int, callable|array|string> $handlers
     */
    private function define(array $methods, string $path, array $handlers, bool $documented = true): static
    {
        $last = array_pop($handlers);
        $middleware = [...$this->scope, ...array_map(self::resolve(...), $handlers)];
        $this->routes[] = new Route($methods, $this->prefix . $path, $middleware, $last, self::resolve($last), $documented);
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
            $middleware = array_map(static fn(callable $next) => new Prefixed($prefix, $next), $middleware);
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
     * Define routes from other files: load('routes/users.php', 'routes/admin/*.php').
     * A relative path is relative to the file that calls load(), and a pattern loads its
     * matches in name order. A file returns function (App $app) { ... }, like app.php, or
     * uses $app directly. Inside a group, the routes get the group's prefix and middleware.
     */
    public function load(string ...$paths): static
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? null;
        $base = $caller === null ? (string)getcwd() : dirname($caller);

        foreach ($paths as $path) {
            if (!str_starts_with($path, '/')) {
                $path = "$base/$path";
            }
            $files = strpbrk($path, '*?[') === false ? [$path] : (glob($path) ?: []);
            $files = array_values(array_filter($files, is_file(...)));
            if ($files === []) {
                throw new InvalidArgumentException("load('$path') found no file");
            }

            foreach ($files as $file) {
                // Static and in its own scope, so the file sees $app but not $this.
                $define = (static fn(App $app) => require $file)($this);
                if (is_callable($define)) {
                    $define($this);
                } elseif ($define !== 1 && $define !== null) {
                    throw new InvalidArgumentException("$file must return a callable that receives Kuman\\App, or nothing");
                }
            }
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
        return $this->define(['GET'], $path, [...$handlers, function (Request $request) use ($on) {
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
        }], false);
    }

    /**
     * Server-Sent Events: the last handler receives the request and a Kuman\Sse stream.
     */
    public function sse(string $path, callable|array|string ...$handlers): static
    {
        $handlers = array_map(self::resolve(...), $handlers);
        $handler = array_pop($handlers);
        return $this->define(['GET'], $path, [...$handlers, function (Request $request) use ($handler) {
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
        }], false);
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

    /**
     * Render validation failures: fn(Invalid $e, Request $request) => response.
     * $e->details lists field, rule and message.
     */
    public function onInvalid(callable $handler): static
    {
        $this->invalidHandler = $handler;
        return $this;
    }

    public function start(Worker $worker): void
    {
        foreach ($this->routes as $route) {
            $this->router->add($route->methods, $route->path, self::pipe($route->middleware, $this->answer($route)));
        }
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
        // Thrown outside an injected handler, so the middleware will not see this answer.
        if ($e instanceof Abort) {
            try {
                return Response::from($this->aborted($e, $request));
            } catch (Throwable $failed) {
                $e = $failed;
            }
        }
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

    private function aborted(Abort $e, Request $request): mixed
    {
        return $e instanceof Invalid && $this->invalidHandler !== null
            ? ($this->invalidHandler)($e, $request)
            : $e->response;
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

    /**
     * The last step of a route. Middleware has run by now, so a guard refuses a request
     * before validation looks at it, and the request here is the one holding the user.
     */
    private function answer(Route $route): callable
    {
        $plan = $this->resolver->plan($route->handler, $route->parameters());
        if ($plan !== null) {
            $route->steps = [...$plan['steps'], ...$plan['constructor']];
            return function (Request $request) use ($plan): mixed {
                try {
                    return $this->resolver->handle($plan, $request);
                } catch (Abort $e) {
                    // Returned, not left to render(): middleware such as CORS must see it.
                    return $this->aborted($e, $request);
                }
            };
        }

        $callable = $route->callable;
        if (!Resolver::wantsResponse(Resolver::reflect($route->handler))) {
            return $callable;
        }
        return static fn(Request $request) => $callable($request, new Response(body: Stream::from()), $request->params);
    }

    /**
     * Turn [Controller::class, 'method'] and invokable class names into callables. Checked
     * when the route is defined, so a typo stops the worker at start instead of failing a request.
     */
    private static function resolve(callable|array|string $handler): callable
    {
        if (is_callable($handler)) {
            return $handler;
        }

        [$class, $method] = is_array($handler) ? [$handler[0] ?? null, $handler[1] ?? null] : [$handler, '__invoke'];
        if (!is_string($class) || !is_string($method) || !class_exists($class)) {
            throw new InvalidArgumentException('Route handler must be a callable, an invokable class or [Controller::class, \'method\'].');
        }
        if (!method_exists($class, $method) || !(new ReflectionMethod($class, $method))->isPublic()) {
            throw new InvalidArgumentException("$class has no public method $method().");
        }

        // A new controller per request: the worker serves many requests, and state must not leak between them.
        return static fn(mixed ...$args) => (new $class())->$method(...$args);
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
