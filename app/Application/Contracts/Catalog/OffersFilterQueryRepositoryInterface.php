<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Application\Catalog\DTOs\ProductFilterChangeDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface OffersFilterQueryRepositoryInterface
{
    /**
     * Find products whose current "Offers → On Sale" filter entry differs from the desired state.
     *
     * Each row's `desiredFilterValues` is the full merge-preserving desired contents of
     * `filters->'14'` (siblings like "Free Delivery" included) — not just the On Sale toggle.
     *
     * @return list<ProductFilterChangeDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidEnumValueException
     */
    public function getProductsWithChangedOffersFilters(): array;
}
