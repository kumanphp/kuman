<?php

declare(strict_types=1);

namespace Kuman\Validation\Attributes;

use Attribute;
use Kuman\Validation\Rule;

/**
 * A field without a default is required already. This also refuses an empty value,
 * overrides a default, and sets the message.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Required extends Rule
{
    public function passes(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    protected function fallback(): string
    {
        return 'This field is required.';
    }
}
