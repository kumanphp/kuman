<?php

declare(strict_types=1);

namespace Kuman\Validation;

use Kuman\Abort;
use Kuman\Response;

/**
 * The request failed validation. App::onInvalid() replaces the default 422 answer.
 */
final class Invalid extends Abort
{
    /** @var array<int, array{field: string, rule: string, message: string}> */
    public readonly array $details;

    /**
     * @param array<int, array{field: string, rule: string, message: string}> $details
     */
    public function __construct(array $details)
    {
        // A dependency and the handler may read the same field.
        $this->details = array_values(array_unique($details, SORT_REGULAR));
        parent::__construct(Response::json(['error' => 'Unprocessable Entity', 'details' => $this->details], 422));
    }
}
