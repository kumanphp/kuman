<?php

declare(strict_types=1);

namespace Kuman;

use RuntimeException;

/**
 * Stops a request with an answer. Thrown from an injected handler or a dependency, the
 * answer is returned through the middleware like any other response, and nothing is logged.
 */
class Abort extends RuntimeException
{
    public function __construct(public readonly mixed $response)
    {
        parent::__construct('Request aborted.');
    }
}
