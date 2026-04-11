<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Application\Catalog\DTOs\ProductSortOrderChangeDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface ProductSortOrderQueryRepositoryInterface
{
    /**
     * Find active products whose live sort_order differs from the calculated sort order
     * in the latest popularity snapshot.
     *
     * @return list<ProductSortOrderChangeDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getProductsWithSortOrderDifferences(): array;
}
