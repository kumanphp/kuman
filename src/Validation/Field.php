<?php

declare(strict_types=1);

namespace Kuman\Validation;

use BackedEnum;
use InvalidArgumentException;
use Kuman\Attributes\Description;
use Kuman\Validation\Attributes\ArrayOf;
use Kuman\Validation\Attributes\Required;
use Psr\Http\Message\UploadedFileInterface;
use ReflectionAttribute;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

/**
 * One value read from a request: a property of a Dto or a parameter of a handler. Built
 * once when the worker starts, so a type it cannot read stops the worker there.
 *
 * A field is required when its type does not allow null and it has no default. JSON
 * must match the type exactly. Forms, query strings, headers and paths only hold
 * strings, so they are converted.
 */
final class Field
{
    private const BUILTIN = ['string', 'int', 'float', 'bool', 'array', 'mixed'];

    private const EXPECTED = [
        'string' => 'Must be a string.',
        'int' => 'Must be an integer.',
        'float' => 'Must be a number.',
        'bool' => 'Must be true or false.',
        'array' => 'Must be an array.',
        'dto' => 'Must be an object.',
        'upload' => 'Must be an uploaded file.',
    ];

    /** One of BUILTIN, 'dto', 'enum' or 'upload'. */
    public readonly string $kind;

    /** The class of a dto, enum or upload field. */
    public readonly ?string $class;

    /** 'int' or 'string' for an enum field. */
    public readonly ?string $backing;

    public readonly bool $required;

    public readonly ?string $description;

    /** The type of the items of an array field. */
    public readonly ?self $items;

    /** @var array<int, Rule> */
    public readonly array $rules;

    private readonly Required $requirement;

    /** Set by #[Required], which also refuses empty values. */
    private readonly bool $explicit;

    private function __construct(
        public readonly string $name,
        string $type,
        string $where,
        public readonly bool $nullable = false,
        public readonly bool $hasDefault = false,
        public readonly mixed $default = null,
        ReflectionProperty|ReflectionParameter|null $source = null,
        public readonly ?ReflectionProperty $property = null,
    ) {
        [$this->kind, $this->class] = self::kind($type, $where);
        $this->backing = $this->kind === 'enum' ? (string)(new ReflectionEnum($this->class))->getBackingType() : null;

        $rules = array_map(
            static fn(ReflectionAttribute $attribute) => $attribute->newInstance(),
            $source?->getAttributes(Rule::class, ReflectionAttribute::IS_INSTANCEOF) ?? []
        );
        $required = array_values(array_filter($rules, static fn(Rule $rule) => $rule instanceof Required));
        $this->rules = array_values(array_filter($rules, static fn(Rule $rule) => !$rule instanceof Required));
        $this->explicit = $required !== [];
        $this->requirement = $required[0] ?? new Required();
        $this->required = $this->explicit || (!$hasDefault && !$nullable);

        $items = $source?->getAttributes(ArrayOf::class)[0] ?? null;
        $this->items = $items === null ? null : new self('', $items->newInstance()->type, $where);

        $description = $source?->getAttributes(Description::class)[0] ?? null;
        $this->description = $description?->newInstance()->text;
    }

    public static function fromProperty(ReflectionProperty $property): self
    {
        $hasDefault = $property->hasDefaultValue();
        $default = $hasDefault ? $property->getDefaultValue() : null;
        if ($property->isPromoted()) {
            // The constructor does not run, so its defaults have to be applied here.
            foreach ($property->getDeclaringClass()->getConstructor()->getParameters() as $parameter) {
                if ($parameter->getName() === $property->getName() && $parameter->isDefaultValueAvailable()) {
                    $hasDefault = true;
                    $default = $parameter->getDefaultValue();
                }
            }
        }
        $where = "{$property->getDeclaringClass()->getName()}::\${$property->getName()}";
        $type = $property->getType();
        return new self(
            $property->getName(),
            self::typeName($type, $where),
            $where,
            $type === null || $type->allowsNull(),
            $hasDefault,
            $default,
            $property,
            $property,
        );
    }

    public static function fromParameter(ReflectionParameter $parameter, ?string $name = null): self
    {
        $where = "Parameter \${$parameter->getName()}";
        $hasDefault = $parameter->isDefaultValueAvailable();
        return new self(
            $name ?? $parameter->getName(),
            self::typeName($parameter->getType(), $where),
            $where,
            $parameter->allowsNull(),
            $hasDefault,
            $hasDefault ? $parameter->getDefaultValue() : null,
            $parameter,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array{field: string, rule: string, message: string}> $errors
     * @return mixed The value of the field. Undefined when this added to $errors.
     */
    public function read(array $data, bool $strict, array &$errors, string $prefix = ''): mixed
    {
        $value = $data[$this->name] ?? null;
        $path = $prefix . $this->name;
        if ($value === null) {
            if ($this->required) {
                $errors[] = $this->requirement->failure($path);
                return null;
            }
            return $this->nullable && array_key_exists($this->name, $data) ? null : $this->default;
        }
        if ($this->explicit && !$this->requirement->passes($value)) {
            $errors[] = $this->requirement->failure($path);
            return null;
        }
        return $this->check($value, $strict, $errors, $path);
    }

    /**
     * @param array<int, array{field: string, rule: string, message: string}> $errors
     */
    private function check(mixed $value, bool $strict, array &$errors, string $path): mixed
    {
        if (!$this->cast($value, $strict, $errors, $path)) {
            $errors[] = ['field' => $path, 'rule' => 'type', 'message' => $this->expected()];
            return null;
        }
        foreach ($this->rules as $rule) {
            if (!$rule->passes($value)) {
                $errors[] = $rule->failure($path);
                break;
            }
        }
        return $value;
    }

    /**
     * Turns the value into the declared type, false when it cannot be. Nothing reaches a
     * typed property unchecked: a TypeError there would be a 500 caused by the client.
     *
     * @param array<int, array{field: string, rule: string, message: string}> $errors
     */
    private function cast(mixed &$value, bool $strict, array &$errors, string $path): bool
    {
        switch ($this->kind) {
            case 'string':
                return is_string($value);
            case 'int':
                if (!$strict && is_string($value)) {
                    $value = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? $value;
                }
                return is_int($value);
            case 'float':
                if (is_int($value) || (!$strict && is_string($value) && is_numeric($value))) {
                    $value = (float)$value;
                }
                // 1e999 decodes to INF, which json_encode() refuses later.
                return is_float($value) && is_finite($value);
            case 'bool':
                if (!$strict && is_string($value) && $value !== '') {
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value;
                }
                return is_bool($value);
            case 'array':
                if (!is_array($value)) {
                    return false;
                }
                if ($this->items !== null) {
                    foreach ($value as $key => $item) {
                        $value[$key] = $this->items->check($item, $strict, $errors, "$path.$key");
                    }
                }
                return true;
            case 'dto':
                if (!is_array($value)) {
                    return false;
                }
                $value = Hydrator::make($this->class, $value, $strict, $errors, "$path.");
                return true;
            case 'enum':
                return $this->castEnum($value, $strict);
            case 'upload':
                return $value instanceof $this->class && $value->getError() === UPLOAD_ERR_OK;
            default:
                return true;
        }
    }

    private function castEnum(mixed &$value, bool $strict): bool
    {
        if ($this->backing === 'int') {
            if (!$strict && is_string($value)) {
                $value = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? $value;
            }
            if (!is_int($value)) {
                return false;
            }
        } elseif (!is_string($value)) {
            return false;
        }
        $value = $this->class::tryFrom($value);
        return $value !== null;
    }

    private function expected(): string
    {
        if ($this->kind !== 'enum') {
            return self::EXPECTED[$this->kind];
        }
        return 'Must be one of: ' . implode(', ', array_column($this->class::cases(), 'value')) . '.';
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private static function kind(string $type, string $where): array
    {
        if (in_array($type, self::BUILTIN, true)) {
            return [$type, null];
        }
        if (is_subclass_of($type, Dto::class)) {
            Hydrator::fields($type);
            return ['dto', $type];
        }
        if (is_subclass_of($type, BackedEnum::class)) {
            return ['enum', $type];
        }
        if (is_a($type, UploadedFileInterface::class, true)) {
            return ['upload', $type];
        }
        throw new InvalidArgumentException("$where has the type $type, which cannot be read from a request.");
    }

    private static function typeName(?ReflectionType $type, string $where): string
    {
        if ($type !== null && !$type instanceof ReflectionNamedType) {
            throw new InvalidArgumentException("$where has a union type, which cannot be read from a request.");
        }
        return $type?->getName() ?? 'mixed';
    }
}
