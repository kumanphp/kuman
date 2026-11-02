<?php

declare(strict_types=1);

namespace Kuman\Validation;

use ReflectionClass;

/**
 * A validation attribute. One instance serves every request of a worker, so a rule
 * keeps nothing between calls. It only runs on a value of the declared type.
 */
abstract class Rule
{
    public function __construct(public readonly string $message = '')
    {
    }

    abstract public function passes(mixed $value): bool;

    abstract protected function fallback(): string;

    /**
     * @param string $kind The kind of the field, see Field.
     * @return array<string, mixed> OpenAPI keywords describing this rule.
     */
    public function schema(string $kind): array
    {
        return [];
    }

    /**
     * @return array{field: string, rule: string, message: string}
     */
    public function failure(string $field): array
    {
        $rule = lcfirst((new ReflectionClass($this))->getShortName());
        return ['field' => $field, 'rule' => $rule, 'message' => $this->message !== '' ? $this->message : $this->fallback()];
    }

    protected static function length(mixed $value): int
    {
        if (is_array($value)) {
            return count($value);
        }
        $value = (string)$value;
        // Bytes of a string that is not UTF-8, form bodies are not checked.
        return preg_match_all('/./su', $value) ?: strlen($value);
    }
}
