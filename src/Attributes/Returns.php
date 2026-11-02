<?php

declare(strict_types=1);

namespace Kuman\Attributes;

use Attribute;

/**
 * A response of the operation in the OpenAPI document. Not named Response, which is
 * what handlers import to answer.
 */
#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Returns
{
    /**
     * @param string|array{0: string}|null $type A class or scalar type, [Item::class] for a list.
     * @param string $description The reason phrase of the status when empty.
     */
    public function __construct(
        public readonly int $status = 200,
        public readonly string|array|null $type = null,
        public readonly string $description = '',
    ) {
    }
}
