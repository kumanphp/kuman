<?php

declare(strict_types=1);

namespace Kuman;

use BackedEnum;
use InvalidArgumentException;
use Kuman\Attributes\Description;
use Kuman\Attributes\Hidden;
use Kuman\Attributes\Returns;
use Kuman\Attributes\Summary;
use Kuman\Attributes\Tag;
use Kuman\Auth\Guard;
use Kuman\Validation\Field;
use Kuman\Validation\Hydrator;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use stdClass;
use Stringable;
use Workerman\Protocols\Http\Response as BaseResponse;

/**
 * Builds an OpenAPI 3.1 document from the routes as they were defined. Built on the
 * first request for it, so a worker that never serves the docs pays nothing.
 */
final class OpenApi
{
    /** Pinned with integrity hashes: a page that holds tokens must not run whatever a CDN serves next. */
    public const PAGES = [
        'scalar' => '<script id="api-reference" data-url="{spec}"></script>'
            . '<script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.74.0/dist/browser/standalone.js"'
            . ' integrity="sha384-VXnKAz61Xwc3tbw1P+KKrUP7YbcsSl1Ik4is5UnogJyw9XEYZ5Ay/T1i0zPYtSZH" crossorigin="anonymous"></script>',
        'swagger' => '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css"'
            . ' integrity="sha384-wxLW6kwyHktdDGr6Pv1zgm/VGJh99lfUbzSn6HNHBENZlCN7W602k9VkGdxuFvPn" crossorigin="anonymous">'
            . '<div id="ui" data-url="{spec}"></div>'
            . '<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"'
            . ' integrity="sha384-wmyclcVGX/WhUkdkATwhaK1X1JtiNrr2EoYJ+diV3vj4v6OC5yCeSu+yW13SYJep" crossorigin="anonymous"></script>'
            . '<script>SwaggerUIBundle({url: document.getElementById("ui").dataset.url, dom_id: "#ui"});</script>',
        'redoc' => '<redoc spec-url="{spec}"></redoc>'
            . '<script src="https://cdn.jsdelivr.net/npm/redoc@2.1.5/bundles/redoc.standalone.js"'
            . ' integrity="sha384-0GrsyTQc9Oqd8h+b2dbc4XdR2T/DYpy0tLNNstyx+LBMUyiBbcWPbEs9aRmUcaxD" crossorigin="anonymous"></script>',
    ];

    /** Methods an OpenAPI path item can hold. */
    private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /** What a route defined with all() is documented as. */
    private const ANY = ['get', 'post', 'put', 'patch', 'delete'];

    private const SCALARS = [
        'string' => ['type' => 'string'],
        'int' => ['type' => 'integer'],
        'float' => ['type' => 'number'],
        'bool' => ['type' => 'boolean'],
        'array' => ['type' => 'array'],
    ];

    private const VALIDATION_ERROR = [
        'type' => 'object',
        'required' => ['error', 'details'],
        'properties' => [
            'error' => ['type' => 'string'],
            'details' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'required' => ['field', 'rule', 'message'],
                    'properties' => [
                        'field' => ['type' => 'string'],
                        'rule' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ];

    /** @var array<string, mixed> */
    private array $schemas = [];

    /** @var array<string, string> Class => name in components/schemas. */
    private array $names = [];

    /** @var array<string, array<string, string>> */
    private array $schemes = [];

    /**
     * @param array<int, Route> $routes
     * @param array<int, callable> $global Middleware of the kernel.
     */
    public function __construct(
        private readonly array $routes,
        private readonly array $global,
        private readonly string $title,
        private readonly string $version,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        $paths = [];
        foreach ($this->routes as $route) {
            $function = Resolver::reflect($route->handler);
            if (!$route->documented || self::attributes($function, Hidden::class) !== []) {
                continue;
            }
            $methods = $route->methods === ['*'] ? self::ANY : array_map('strtolower', $route->methods);
            foreach (Router::parse($route->path) as $segments) {
                [$path, $parameters] = self::path($segments);
                foreach (array_intersect($methods, self::METHODS) as $method) {
                    $paths[$path][$method] = $this->operation($route, $function, $method, $path, $parameters);
                }
            }
        }

        $document = [
            'openapi' => '3.1.0',
            'info' => ['title' => $this->title, 'version' => $this->version],
            'paths' => $paths === [] ? new stdClass() : $paths,
        ];
        $components = array_filter(['schemas' => $this->schemas, 'securitySchemes' => $this->schemes]);
        return $components === [] ? $document : $document + ['components' => $components];
    }

    public static function page(string $ui, string $title, string $spec): string
    {
        $title = htmlspecialchars($title, ENT_QUOTES);
        $body = str_replace('{spec}', htmlspecialchars($spec, ENT_QUOTES), self::PAGES[$ui]);
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . "<title>$title</title></head><body>$body</body></html>";
    }

    /**
     * @param array<int, string|array{0: string, 1: string}> $segments
     * @return array{0: string, 1: array<string, array<string, mixed>>}
     */
    private static function path(array $segments): array
    {
        $path = '';
        $parameters = [];
        foreach ($segments as $segment) {
            if (is_string($segment)) {
                $path .= $segment;
                continue;
            }
            [$name, $regex] = $segment;
            $path .= '{' . $name . '}';
            $schema = ['type' => 'string'];
            if ($regex !== '[^/]+' && $regex !== '.*') {
                $schema['pattern'] = "^$regex$";
            }
            $parameters["path:$name"] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => $schema];
            if ($regex === '.*') {
                // OpenAPI path parameters cannot hold a slash, so a wildcard can only be explained.
                $parameters["path:$name"]['description'] = 'The rest of the path, slashes included.';
            }
        }
        return [$path, $parameters];
    }

    /**
     * @param array<string, array<string, mixed>> $parameters
     * @return array<string, mixed>
     */
    private function operation(Route $route, ReflectionFunctionAbstract $function, string $method, string $path, array $parameters): array
    {
        $operation = ['operationId' => $method . '_' . trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $path), '_')];
        foreach ([Summary::class => 'summary', Description::class => 'description'] as $class => $key) {
            $found = self::attributes($function, $class);
            if ($found !== []) {
                $operation[$key] = $found[0]->text;
            }
        }
        $tags = array_map(static fn(Tag $tag) => $tag->name, [...self::attributes($function, Tag::class), ...self::classTags($function)]);
        if ($tags !== []) {
            $operation['tags'] = array_values(array_unique($tags));
        }

        $bodies = [];
        $inline = [];
        $validated = false;
        foreach (self::flatten($route->steps) as $step) {
            $validated = true;
            if ($step[0] === 'field') {
                if ($step[1] === 'body') {
                    $inline[] = $step[2];
                } else {
                    $parameters = $this->parameter($parameters, $step[2], $step[1]);
                }
                continue;
            }
            $in = $step[2] ?? Resolver::defaultSource(strtoupper($method));
            if ($in === 'body') {
                $bodies[] = $step[1];
                continue;
            }
            foreach (Hydrator::fields($step[1]) as $field) {
                $parameters = $this->parameter($parameters, $field, $in);
            }
        }
        if ($parameters !== []) {
            $operation['parameters'] = array_values($parameters);
        }
        if ($bodies !== [] || $inline !== []) {
            $operation['requestBody'] = $this->requestBody($bodies, $inline);
        }

        [$security, $guarded] = $this->security($route);
        if ($security !== []) {
            $operation['security'] = $security;
        }
        $operation['responses'] = $this->responses($function, $validated, $guarded);
        return $operation;
    }

    /**
     * Everything that reads the request, with the steps of dependencies.
     *
     * @param array<int, array> $steps
     * @return iterable<array>
     */
    private static function flatten(array $steps): iterable
    {
        foreach ($steps as $step) {
            if ($step[0] === 'depends') {
                yield from self::flatten([...$step[2]['steps'], ...$step[2]['constructor']]);
            } elseif ($step[0] === 'field' || $step[0] === 'dto') {
                yield $step;
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $parameters
     * @return array<string, array<string, mixed>>
     */
    private function parameter(array $parameters, Field $field, string $in): array
    {
        $key = "$in:$field->name";
        $known = $parameters[$key] ?? [];
        $schema = $this->schema($field);
        if ($field->kind === 'string' && isset($known['schema']['pattern'])) {
            $schema += ['pattern' => $known['schema']['pattern']];
        }

        $parameter = ['name' => $field->name, 'in' => $in];
        $description = $field->description ?? $known['description'] ?? null;
        if ($description !== null) {
            $parameter['description'] = $description;
        }
        if ($in === 'path' || $field->required) {
            $parameter['required'] = true;
        }
        $parameters[$key] = $parameter + ['schema' => $schema === [] ? new stdClass() : $schema];
        return $parameters;
    }

    /**
     * @param array<int, string> $bodies Dto classes.
     * @param array<int, Field> $inline Single fields read from the body.
     * @return array<string, mixed>
     */
    private function requestBody(array $bodies, array $inline): array
    {
        $schemas = [];
        $required = false;
        $fields = $inline;
        foreach (array_unique($bodies) as $class) {
            $schemas[] = $this->reference($class);
            $fields = [...$fields, ...array_values(Hydrator::fields($class))];
        }
        if ($inline !== []) {
            $schemas[] = $this->object($inline);
        }
        foreach ($fields as $field) {
            $required = $required || $field->required;
        }

        $uploads = array_filter($fields, static fn(Field $field) => $field->kind === 'upload' || $field->items?->kind === 'upload');
        $media = $uploads === [] ? 'application/json' : 'multipart/form-data';
        $body = ['content' => [$media => ['schema' => count($schemas) === 1 ? $schemas[0] : ['allOf' => $schemas]]]];
        return $required ? $body + ['required' => true] : $body;
    }

    /**
     * @return array{0: array<int, object>, 1: bool} Requirements, and whether a guard can refuse.
     */
    private function security(Route $route): array
    {
        // A requirement holds schemes that are all needed, the list holds alternatives.
        $requirements = [[]];
        $guarded = false;
        foreach ([...$this->global, ...$route->middleware] as $middleware) {
            if ($middleware instanceof Prefixed) {
                if (!$middleware->covers('/' . ltrim($route->path, '/'))) {
                    continue;
                }
                $middleware = $middleware->middleware;
            }
            if (!$middleware instanceof Guard) {
                continue;
            }

            $guarded = $guarded || !$middleware->isOptional();
            $schemes = $middleware->security();
            if ($schemes === []) {
                continue;
            }
            $this->schemes = $schemes + $this->schemes;
            $alternatives = array_map(static fn(string $name) => [$name => []], array_keys($schemes));
            if ($middleware->isOptional()) {
                $alternatives[] = [];
            }

            $combined = [];
            foreach ($requirements as $requirement) {
                foreach ($alternatives as $alternative) {
                    $combined[] = $requirement + $alternative;
                }
            }
            $requirements = $combined;
        }
        return [$requirements === [[]] ? [] : array_map(static fn(array $requirement) => (object)$requirement, $requirements), $guarded];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function responses(ReflectionFunctionAbstract $function, bool $validated, bool $guarded): array
    {
        $responses = [];
        foreach (self::attributes($function, Returns::class) as $returns) {
            $responses[$returns->status] = $this->response($returns->status, $returns->type, $returns->description);
        }
        if ($responses === []) {
            $responses[200] = $this->response(200, self::returnType($function), '');
        }
        if ($validated && !isset($responses[422])) {
            $this->schemas['ValidationError'] = self::VALIDATION_ERROR;
            $responses[422] = [
                'description' => 'Validation failed',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationError']]],
            ];
        }
        if ($guarded && !isset($responses[401])) {
            $responses[401] = ['description' => 'Unauthorized'];
        }
        ksort($responses);
        return $responses;
    }

    /**
     * @param string|array{0: string}|null $type
     * @return array<string, mixed>
     */
    private function response(int $status, string|array|null $type, string $description): array
    {
        $response = ['description' => $description !== '' ? $description : (new Response($status))->getReasonPhrase()];
        if ($type === null) {
            return $response;
        }
        return $response + ['content' => [$type === 'string' ? 'text/html' : 'application/json' => ['schema' => $this->payload($type)]]];
    }

    /**
     * @param string|array{0: string} $type
     * @return array<string, mixed>
     */
    private function payload(string|array $type): array
    {
        if (is_array($type)) {
            return ['type' => 'array', 'items' => $this->payload($type[0])];
        }
        return self::SCALARS[$type] ?? $this->reference($type);
    }

    /**
     * What a handler declares returning, when it says more than "a response".
     */
    private static function returnType(ReflectionFunctionAbstract $function): ?string
    {
        $type = $function->getReturnType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin() || !class_exists($type->getName())) {
            return null;
        }
        foreach ([ResponseInterface::class, BaseResponse::class, Stringable::class] as $opaque) {
            if (is_a($type->getName(), $opaque, true)) {
                return null;
            }
        }
        return $type->getName();
    }

    /**
     * @return array{'$ref': string}
     */
    private function reference(string $class): array
    {
        if (!isset($this->names[$class])) {
            $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', (new ReflectionClass($class))->getShortName());
            if (isset($this->schemas[$name]) || $name === 'ValidationError') {
                $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', str_replace('\\', '.', $class));
            }
            // Before its fields: a class may hold itself.
            $this->names[$class] = $name;
            $this->schemas[$name] = [];
            try {
                $this->schemas[$name] = $this->object(array_values(Hydrator::fields($class)));
            } catch (InvalidArgumentException) {
                // A response type need not be a Dto, and may hold types no request can.
                $this->schemas[$name] = ['type' => 'object'];
            }
        }
        return ['$ref' => "#/components/schemas/{$this->names[$class]}"];
    }

    /**
     * @param array<int, Field> $fields
     * @return array<string, mixed>
     */
    private function object(array $fields): array
    {
        $properties = [];
        $required = [];
        foreach ($fields as $field) {
            $schema = $this->schema($field);
            $properties[$field->name] = $schema === [] ? new stdClass() : $schema;
            if ($field->required) {
                $required[] = $field->name;
            }
        }
        $object = ['type' => 'object'];
        if ($properties !== []) {
            $object['properties'] = $properties;
        }
        return $required === [] ? $object : $object + ['required' => $required];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(Field $field): array
    {
        $schema = match ($field->kind) {
            'mixed' => [],
            'dto' => $this->reference($field->class),
            'upload' => ['type' => 'string', 'format' => 'binary'],
            'enum' => ['type' => $field->backing === 'int' ? 'integer' : 'string', 'enum' => array_column($field->class::cases(), 'value')],
            default => self::SCALARS[$field->kind],
        };
        if ($field->items !== null) {
            $schema['items'] = $this->schema($field->items);
        }
        foreach ($field->rules as $rule) {
            $schema += $rule->schema($field->kind);
        }
        if ($field->nullable && $field->kind !== 'mixed') {
            $schema = isset($schema['$ref']) || isset($schema['enum'])
                ? ['anyOf' => [$schema, ['type' => 'null']]]
                : ['type' => [$schema['type'], 'null']] + $schema;
        }
        if ($field->description !== null) {
            $schema['description'] = $field->description;
        }
        if ($field->hasDefault) {
            $schema['default'] = $field->default instanceof BackedEnum ? $field->default->value : $field->default;
        }
        return $schema;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return array<int, T>
     */
    private static function attributes(ReflectionFunctionAbstract $function, string $class): array
    {
        $attributes = $function->getAttributes($class);
        if ($class === Hidden::class) {
            $attributes = [...$attributes, ...(self::owner($function)?->getAttributes($class) ?? [])];
        }
        return array_map(static fn($attribute) => $attribute->newInstance(), $attributes);
    }

    /**
     * @return array<int, Tag>
     */
    private static function classTags(ReflectionFunctionAbstract $function): array
    {
        return array_map(static fn($attribute) => $attribute->newInstance(), self::owner($function)?->getAttributes(Tag::class) ?? []);
    }

    private static function owner(ReflectionFunctionAbstract $function): ?ReflectionClass
    {
        return $function instanceof ReflectionMethod ? $function->getDeclaringClass() : $function->getClosureScopeClass();
    }
}
