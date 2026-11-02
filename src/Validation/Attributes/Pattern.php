<?php

declare(strict_types=1);

namespace Kuman\Validation\Attributes;

use Attribute;
use InvalidArgumentException;
use Kuman\Validation\Rule;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Pattern extends Rule
{
    /**
     * @param string $pattern A complete PCRE pattern, with delimiters.
     */
    public function __construct(public readonly string $pattern, string $message = '')
    {
        // Attributes are read at start, so a broken pattern stops the worker there.
        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException("Invalid pattern: $pattern");
        }
        parent::__construct($message);
    }

    public function passes(mixed $value): bool
    {
        return is_string($value) && preg_match($this->pattern, $value) === 1;
    }

    public function schema(string $kind): array
    {
        // JSON Schema patterns have no delimiters, so flags cannot be expressed.
        $delimiter = $this->pattern[0];
        return str_ends_with($this->pattern, $delimiter) ? ['pattern' => substr($this->pattern, 1, -1)] : [];
    }

    protected function fallback(): string
    {
        return 'Has an invalid format.';
    }
}
