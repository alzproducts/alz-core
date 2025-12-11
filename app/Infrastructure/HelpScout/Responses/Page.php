<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use Spatie\LaravelData\Data;

/**
 * Pagination metadata from HelpScout API response.
 */
final class Page extends Data
{
    public function __construct(
        public readonly int $size,
        public readonly int $totalElements,
        public readonly int $totalPages,
        public readonly int $number,
    ) {}

    /**
     * Check if there are more pages available.
     */
    public function hasMorePages(): bool
    {
        return $this->number < $this->totalPages;
    }
}
