<?php

declare(strict_types=1);

namespace Kuman\Attributes;

use Attribute;

/**
 * Groups operations in the OpenAPI document. On a controller it applies to its methods.
 */
#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Tag
{
    public function __construct(public readonly string $name)
    {
    }
}
