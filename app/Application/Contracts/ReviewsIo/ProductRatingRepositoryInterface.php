<?php

declare(strict_types=1);

namespace App\Application\Contracts\ReviewsIo;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Domain\Catalog\Product\ValueObjects\ProductRating;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Repository for Reviews.io product ratings persistence.
 *
 * Handles the reviews_io.product_ratings table only.
 * Used by Stage 1 (API→DB) sync use case.
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
}
