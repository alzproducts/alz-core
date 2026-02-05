<?php

declare(strict_types=1);

namespace App\Application\Contracts\ReviewsIo;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Application\ReviewsIo\DTOs\ProductRatingChangeDTO;
use App\Domain\Catalog\Product\ValueObjects\ProductRating;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Repository for Reviews.io product ratings persistence.
 *
 * Handles the reviews_io.product_ratings table only.
 * Used by Stage 1 (API→DB) and Stage 2 (DB→ShopWired) use cases.
 *
 * @extends RepositoryWriteInterface<ProductRating>
 */
interface ProductRatingRepositoryInterface extends RepositoryWriteInterface
{
    /**
     * Get ratings for specific SKUs.
     *
     * Returns ratings for the requested SKUs that exist in the database.
     * SKUs without ratings are not included in the result.
     *
     * @param list<string> $skus SKUs to look up
     *
     * @return list<ProductRating> Ratings found (may be fewer than requested)
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getBySkus(array $skus): array;

    /**
     * Find products where calculated ratings differ from ShopWired custom fields.
     *
     * Performs a single SQL query that:
     * - Aggregates ratings across all SKUs for each product (weighted average)
     * - Compares against current custom_fields values
     * - Returns only products where values have changed
     *
     * @return list<ProductRatingChangeDTO> Products needing updates
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getProductsWithChangedRatings(): array;
}
