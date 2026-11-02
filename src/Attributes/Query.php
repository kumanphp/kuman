<?php

declare(strict_types=1);

namespace Kuman\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Query extends Source
{
    public const IN = 'query';
}
