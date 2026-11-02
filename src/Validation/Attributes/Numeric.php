<?php

declare(strict_types=1);

namespace Kuman\Validation\Attributes;

use Attribute;
use Kuman\Validation\Rule;

/**
 * For a string that must hold a number, such as a price kept as text. A field typed
 * int or float does not need it.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Numeric extends Rule
{
    public function passes(mixed $value): bool
    {
        return is_numeric($value);
    }

    protected function fallback(): string
    {
        return 'Must be a number.';
    }
}
