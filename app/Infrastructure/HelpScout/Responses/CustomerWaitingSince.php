<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use Spatie\LaravelData\Data;

/**
 * Customer waiting time information.
 *
 * Contains both ISO timestamp and human-friendly format.
 */
final class CustomerWaitingSince extends Data
{
    public function __construct(
        public readonly string $time,
        public readonly string $friendly,
    ) {}
}
