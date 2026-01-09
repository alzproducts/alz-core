<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use Spatie\LaravelData\Data;

/**
 * Customer waiting time information.
 *
 * Contains both ISO timestamp and human-friendly format.
 */
final class CustomerWaitingSinceResponse extends Data
{
    /**
     * @param string $time ISO 8601 timestamp (e.g., "2024-01-15T10:30:00Z")
     * @param string $friendly Human-readable duration from HelpScout (e.g., "2 hours ago", "3 days")
     */
    public function __construct(
        public readonly string $time,
        public readonly string $friendly,
    ) {}
}
