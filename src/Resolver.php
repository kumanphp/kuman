<?php

declare(strict_types=1);

namespace Kuman;

use Closure;
use InvalidArgumentException;
use Kuman\Attributes\Depends;
use Kuman\Attributes\Source;
use Kuman\Validation\Dto;
use Kuman\Validation\Field;
use Kuman\Validation\Hydrator;
use Kuman\Validation\Invalid;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Works out what a handler's parameters need when the worker starts, and supplies them
 * for each request. The result of that work, a plan, describes the handler and holds
 * nothing from a request. What a request produces lives in local variables of handle(),
 * so a suspended fiber keeps its own and nothing has to be reset.
 *
 * A step is a list starting with its kind:
 *   ['request'], ['response'], ['params'],
 *   ['service', object], ['default', mixed],
 *   ['field', source, Field], ['dto', class, ?source],
 *   ['depends', key, plan]
 */
final class Resolver
{
    /** Kinds that are part of the call shapes every handler already has. */
    private const PLAIN = ['request', 'response', 'params'];

    private const BODY = "\0body";

    /** @var array<string, object> */
    private array $services = [];

    /** @var array<string, callable|array|string> */
    private array $scoped = [];

    public function provide(string $class, object $service): void
    {
        if (!$service instanceof $class) {
            throw new InvalidArgumentException('provide() takes the object itself, of class ' . $class . '.');
        }
        $this->services[$class] = $service;
    }

    public function scoped(string $class, callable|array|string $factory): void
    {
        $this->scoped[$class] = $factory;
    }

    /**
     * @param array<int, string> $path Names of the route parameters.
     * @return ?array A plan, null when App can call the handler in one of the plain shapes.
     */
    public function plan(callable|array|string $handler, array $path): ?array
    {
        return $this->compile($handler, $path, [], false);
    }

    /**
     * Run the handler of a plan with everything it asked for.
     */
    public function handle(array $plan, Request $request): mixed
    {
        $memo = [];
        $errors = [];
        $result = $this->call($plan, $request, $memo, $errors);
        if ($errors !== []) {
            throw new Invalid($errors);
        }
        return $result;
    }

    public static function reflect(callable|array|string $handler): ReflectionFunctionAbstract
    {
        return match (true) {
            is_callable($handler) => new ReflectionFunction(Closure::fromCallable($handler)),
            is_array($handler) => new ReflectionMethod($handler[0], $handler[1]),
            default => new ReflectionMethod($handler, '__invoke'),
        };
    }

    public static function wantsResponse(ReflectionFunctionAbstract $function): bool
    {
        return $function->getNumberOfParameters() > 1 || $function->isVariadic();
    }

    /**
     * @param array<int, string> $stack Dependencies being compiled, to catch a cycle.
     */
    private function compile(callable|array|string $handler, array $path, array $stack, bool $dependency): ?array
    {
        $function = self::reflect($handler);
        $class = null;
        $method = null;
        if (!is_callable($handler)) {
            [$class, $method] = is_array($handler) ? $handler : [$handler, '__invoke'];
        }
        $constructor = $class === null ? [] : $this->constructor($class, $path, $stack);

        $parameters = array_values(array_filter(
            $function->getParameters(),
            static fn(ReflectionParameter $parameter) => !$parameter->isVariadic()
        ));
        $steps = [];
        foreach ($parameters as $parameter) {
            $steps[] = $this->step($parameter, $path, $stack);
        }

        $injected = array_filter($steps, static fn(?array $step) => $step !== null && !in_array($step[0], self::PLAIN, true));
        if ($injected === [] && !$dependency) {
            if ($constructor === []) {
                return null;
            }
            $steps = self::wantsResponse($function) ? [['request'], ['response'], ['params']] : [['request']];
        } else {
            foreach ($steps as $i => $step) {
                if ($step === null || $step[0] === 'unknown') {
                    $steps[$i] = self::fallback($parameters[$i]);
                }
            }
        }
        return ['callable' => $handler, 'class' => $class, 'method' => $method, 'constructor' => $constructor, 'steps' => $steps];
    }

    /**
     * @return array<int, array> Empty when `new $class()` is enough.
     */
    private function constructor(string $class, array $path, array $stack): array
    {
        $steps = [];
        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if (!$parameter->isVariadic()) {
                $step = $this->step($parameter, $path, $stack);
                $steps[] = $step === null || $step[0] === 'unknown' ? self::fallback($parameter) : $step;
            }
        }
        return array_filter($steps, static fn(array $step) => $step[0] !== 'default') === [] ? [] : $steps;
    }

    /**
     * @return ?array Null for a parameter that says nothing, ['unknown'] for a class nobody supplies.
     */
    private function step(ReflectionParameter $parameter, array $path, array $stack): ?array
    {
        $type = $parameter->getType();
        $class = $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;

        $depends = ($parameter->getAttributes(Depends::class)[0] ?? null)?->newInstance();
        if ($depends !== null) {
            $key = is_array($depends->target) ? implode('::', $depends->target) : $depends->target;
            return $this->dependency($key, $depends->target, $path, $stack);
        }

        $source = ($parameter->getAttributes(Source::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null)?->newInstance();
        if ($class !== null) {
            if (is_a(Request::class, $class, true)) {
                return ['request'];
            }
            if (is_a(Response::class, $class, true)) {
                return ['response'];
            }
            if (isset($this->services[$class])) {
                return ['service', $this->services[$class]];
            }
            if (isset($this->scoped[$class])) {
                return $this->dependency($class, $this->scoped[$class], $path, $stack);
            }
            if (is_subclass_of($class, Dto::class)) {
                Hydrator::fields($class);
                return ['dto', $class, $source === null ? null : $source::IN];
            }
        }

        if ($source !== null) {
            return ['field', $source::IN, Field::fromParameter($parameter, self::sourceName($source, $parameter))];
        }
        // Typed only: an untyped parameter is a position in the Slim shape, whatever its name.
        if ($type !== null && in_array($parameter->getName(), $path, true) && $type->__toString() !== 'array') {
            return ['field', 'path', Field::fromParameter($parameter)];
        }
        if ($type instanceof ReflectionNamedType && $type->getName() === 'array') {
            return ['params'];
        }
        return $class === null ? null : ['unknown'];
    }

    private function dependency(string $key, callable|array|string $handler, array $path, array $stack): array
    {
        if (in_array($key, $stack, true)) {
            throw new InvalidArgumentException('Circular dependency: ' . implode(' -> ', [...$stack, $key]));
        }
        return ['depends', $key, $this->compile($handler, $path, [...$stack, $key], true)];
    }

    private static function sourceName(Source $source, ReflectionParameter $parameter): string
    {
        if ($source::IN !== 'header') {
            return $source->name ?? $parameter->getName();
        }
        // Workerman keeps header names in lowercase.
        return strtolower($source->name ?? preg_replace('/(?<!^)[A-Z]/', '-$0', $parameter->getName()));
    }

    private static function fallback(ReflectionParameter $parameter): array
    {
        if ($parameter->isDefaultValueAvailable()) {
            return ['default', $parameter->getDefaultValue()];
        }
        $function = $parameter->getDeclaringFunction();
        $owner = $function instanceof ReflectionMethod ? "{$function->getDeclaringClass()->getName()}::{$function->getName()}()" : 'the handler';
        throw new InvalidArgumentException(
            "Cannot inject \${$parameter->getName()} of $owner: declare a type Kuman can supply, or provide() it."
        );
    }

    /**
     * @return mixed Nothing was called when $errors is not empty.
     */
    private function call(array $plan, Request $request, array &$memo, array &$errors): mixed
    {
        $arguments = $this->arguments($plan['steps'], $request, $memo, $errors);
        if ($plan['class'] === null) {
            return $errors === [] ? $plan['callable'](...$arguments) : null;
        }
        $constructor = $this->arguments($plan['constructor'], $request, $memo, $errors);
        if ($errors !== []) {
            return null;
        }
        return (new ($plan['class'])(...$constructor))->{$plan['method']}(...$arguments);
    }

    /**
     * @return array<int, mixed>
     */
    private function arguments(array $steps, Request $request, array &$memo, array &$errors): array
    {
        $arguments = [];
        foreach ($steps as $step) {
            $arguments[] = match ($step[0]) {
                'request' => $request,
                'response' => new Response(body: Stream::from()),
                'params' => $request->params,
                'service', 'default' => $step[1],
                'field' => $step[2]->read($this->input($step[1], $request, $memo), self::isStrict($step[1], $request), $errors),
                'dto' => $this->dto($step, $request, $memo, $errors),
                'depends' => array_key_exists($step[1], $memo)
                    ? $memo[$step[1]]
                    : $memo[$step[1]] = $this->call($step[2], $request, $memo, $errors),
            };
        }
        return $arguments;
    }

    private function dto(array $step, Request $request, array &$memo, array &$errors): object
    {
        $source = $step[2] ?? self::defaultSource($request->method());
        return Hydrator::make($step[1], $this->input($source, $request, $memo), self::isStrict($source, $request), $errors);
    }

    /**
     * A Dto without a source attribute: POST data never fills from the query string,
     * where it would be open to anyone who can put a link in front of a user.
     */
    public static function defaultSource(string $method): string
    {
        return $method === 'GET' || $method === 'HEAD' ? 'query' : 'body';
    }

    /**
     * @return array<string, mixed>
     */
    private function input(string $source, Request $request, array &$memo): array
    {
        return match ($source) {
            'path' => $request->params,
            'query' => $request->get(),
            'header' => $request->header(),
            'cookie' => $request->cookie(),
            'body' => $memo[self::BODY] ??= self::body($request),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Request $request): array
    {
        $body = (array)($request->getParsedBody() ?? []);
        // Workerman casts a failed json_decode() to an empty array.
        if ($body === [] && self::isJson($request) && $request->rawBody() !== '') {
            json_decode($request->rawBody());
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Abort(Response::json(['error' => 'Bad Request'], 400));
            }
        }
        // And casts a JSON string or number to a list.
        if ($body !== [] && array_is_list($body)) {
            throw new Invalid([['field' => '', 'rule' => 'type', 'message' => 'Must be an object.']]);
        }
        $files = $request->getUploadedFiles();
        return $files === [] ? $body : array_replace_recursive($body, $files);
    }

    private static function isStrict(string $source, Request $request): bool
    {
        return $source === 'body' && self::isJson($request);
    }

    private static function isJson(Request $request): bool
    {
        return str_contains(strtolower((string)$request->header('content-type', '')), 'json');
    }
}
