<?php

declare(strict_types=1);

namespace Kuman\Attributes;

/**
 * Where a handler parameter comes from.
 */
abstract class Source
{
    public const IN = '';

    /**
     * @param ?string $name The name in the request, the name of the parameter when null.
     */
    public function __construct(public readonly ?string $name = null)
    {
    }
}
