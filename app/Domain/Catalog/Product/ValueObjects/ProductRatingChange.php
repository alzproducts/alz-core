<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\ValueObjects\IntId;

/**
 * Represents a product whose ratings need to be updated.
 *
 * Returned by the change detection query that compares
 * current product ratings against stored custom fields.
 */
final readonly class ProductRatingChange
{
    /**
     * @param IntId $productId Product identifier
     * @param string|null $newAverageRating New weighted average (null = no reviews)
     * @param int $newNumRatings New total review count (0 = no reviews)
     */
    public function __construct(
        public IntId $productId,
        public ?string $newAverageRating,
        public int $newNumRatings,
    ) {}
}
