<?php

declare(strict_types=1);

namespace Kuman\Validation\Attributes;

use Attribute;
use Kuman\Validation\Rule;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Email extends Rule
{
    public function passes(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function schema(string $kind): array
    {
        return ['format' => 'email'];
    }

    protected function fallback(): string
    {
        return 'Must be a valid email address.';
    }
}
