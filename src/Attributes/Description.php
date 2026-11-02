<?php

declare(strict_types=1);

namespace Kuman\Attributes;

use Attribute;

/**
 * Longer text for an operation, a Dto field or a parameter in the OpenAPI document.
 */
#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Description
{
    public function __construct(public readonly string $text)
    {
    }
}
