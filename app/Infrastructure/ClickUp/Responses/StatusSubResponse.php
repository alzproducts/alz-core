<?php

declare(strict_types=1);

namespace App\Infrastructure\ClickUp\Responses;

use Spatie\LaravelData\Data;

/**
 * Nested status object inside a ClickUp task.
 */
final class StatusSubResponse extends Data
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $color = null,
    ) {}
}
