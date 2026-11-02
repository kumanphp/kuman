<?php

declare(strict_types=1);

namespace Kuman\Attributes;

use Attribute;

/**
 * A short title for the operation in the OpenAPI document.
 */
#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
final class Summary
{
    public function __construct(public readonly string $text)
    {
    }
}
