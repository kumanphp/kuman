<?php

declare(strict_types=1);

namespace Kuman\Validation\Attributes;

use Attribute;
use Kuman\Validation\Rule;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Max extends Rule
{
    public function __construct(public readonly int|float $value, string $message = '')
    {
        parent::__construct($message);
    }

    public function passes(mixed $value): bool
    {
        return is_numeric($value) && $value <= $this->value;
    }

    public function schema(string $kind): array
    {
        return ['maximum' => $this->value];
    }

    protected function fallback(): string
    {
        return "Must be at most $this->value.";
    }
}
