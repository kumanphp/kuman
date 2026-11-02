<?php

declare(strict_types=1);

namespace Kuman\Attributes;

use Attribute;

/**
 * Leaves the route out of the OpenAPI document. This hides nothing from clients.
 */
#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class Hidden
{
}
