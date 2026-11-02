<?php

declare(strict_types=1);

namespace Kuman\Validation\Attributes;

use Attribute;

/**
 * The type of the items of an array field: 'int', 'string', a Dto class or an enum.
 * PHP has no typed arrays, so nothing else can say it.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class ArrayOf
{
    public function __construct(public readonly string $type)
    {
    }
}
