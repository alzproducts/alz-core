<?php

declare(strict_types=1);

namespace App\Application\Catalog\Commands;

use App\Domain\ValueObjects\IntId;

/**
 * Represents a product whose ratings need to be updated in ShopWired.
 *
 * Returned by the change detection query that compares
 * reviews_io.product_ratings against shopwired.products.custom_fields.
 */
final readonly class ProductRatingChangeCommand
{
    /**
     * @param IntId $productId ShopWired external product ID
     * @param string|null $newAverageRating New weighted average (null = no reviews)
     * @param int $newNumRatings New total review count (0 = no reviews)
     */
    public function __construct(
        public IntId $productId,
        public ?string $newAverageRating,
        public int $newNumRatings,
    ) {}
}
