<?php

declare(strict_types=1);

namespace Kuman\Attributes;

use Attribute;

/**
 * Without a name, $xTenant reads the header X-Tenant.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Header extends Source
{
    public const IN = 'header';
}
