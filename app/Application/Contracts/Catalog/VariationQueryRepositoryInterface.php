<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Application\Catalog\Queries\VariationListQueryParams;
use App\Domain\Catalog\Product\ValueObjects\VariationListItem;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\PaginatedList;

/**
 * Read-only query repository for standalone variation listing.
 *
 * Queries catalog.product_variations_view with denormalized parent context.
 * Does NOT extend RepositoryWriteInterface — pure read path.
 */
interface VariationQueryRepositoryInterface
{
    /**
     * Paginate variations as standalone rows with denormalized parent context.
     *
     * @return PaginatedList<VariationListItem>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function paginate(VariationListQueryParams $query): PaginatedList;
}
