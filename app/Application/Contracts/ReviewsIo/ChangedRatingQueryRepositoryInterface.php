<?php

declare(strict_types=1);

namespace App\Application\Contracts\ReviewsIo;

use App\Application\ReviewsIo\DTOs\ProductRatingChangeDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Read-side repository for cross-schema product rating change detection.
 *
 * Queries the catalog.products_with_changed_ratings view which spans
 * shopwired and reviews_io schemas.
 */
interface ChangedRatingQueryRepositoryInterface
{
    /**
     * Find products where calculated ratings differ from ShopWired custom fields.
     *
     * Queries a Postgres view that:
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
