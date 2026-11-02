<?php

declare(strict_types=1);

namespace Kuman\Validation\Attributes;

use Attribute;
use Kuman\Validation\Rule;

/**
 * Characters of a string, items of an array.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class MaxLength extends Rule
{
    public function __construct(public readonly int $length, string $message = '')
    {
        parent::__construct($message);
    }

    public function passes(mixed $value): bool
    {
        return self::length($value) <= $this->length;
    }

    public function schema(string $kind): array
    {
        return [$kind === 'array' ? 'maxItems' : 'maxLength' => $this->length];
    }

    protected function fallback(): string
    {
        return "Must have at most $this->length characters.";
    }
}
