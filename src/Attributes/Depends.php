<?php

declare(strict_types=1);

namespace Kuman\Attributes;

use Attribute;

/**
 * Fills a handler parameter with the result of another function, whose own parameters
 * are injected the same way. It runs once per request however many times it is asked for.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Depends
{
    /**
     * @param string|array{0: string, 1: string} $target An invokable class or [SomeClass::class, 'method'].
     *     Attributes cannot hold closures.
     */
    public function __construct(public readonly string|array $target)
    {
    }
}
