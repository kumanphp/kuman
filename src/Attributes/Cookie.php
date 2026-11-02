<?php

declare(strict_types=1);

namespace Kuman\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Cookie extends Source
{
    public const IN = 'cookie';
}
