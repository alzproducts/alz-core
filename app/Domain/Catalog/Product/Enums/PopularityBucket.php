<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Enums;

/**
 * Coarse-grained merchandising bucket over `popularity_rank`.
 *
 * Ranges are hardcoded against the current SKU popularity ranking output
 * (`popularity_max = 12`). If that maximum changes, `rankRange()` must be
 * revised — the buckets are intentionally coupled to the rank scale, not
 * derived dynamically.
 */
enum PopularityBucket: string
{
    case MostPopular = 'most_popular';
    case LeastPopular = 'least_popular';

    /**
     * Inclusive `[min, max]` rank range for this bucket.
     *
     * @return array{int, int}
     */
    public function rankRange(): array
    {
        return match ($this) {
            self::MostPopular => [1, 3],
            self::LeastPopular => [10, 12],
        };
    }
}
