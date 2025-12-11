<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use Spatie\LaravelData\Data;

/**
 * Tag attached to a HelpScout conversation.
 */
final class Tag extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $tag,
        public readonly string $color,
    ) {}
}
