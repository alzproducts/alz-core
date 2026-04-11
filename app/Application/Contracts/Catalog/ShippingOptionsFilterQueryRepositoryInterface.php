<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Application\Catalog\DTOs\ProductFilterChangeDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface ShippingOptionsFilterQueryRepositoryInterface
{
    /**
     * Find products whose current "Shipping Options" filter entry (slot 25) differs from the desired state.
     *
     * Each row's `desiredFilterValues` is derived from stock availability:
     * parent `stock > 0` OR any variation `stock > 0` → Next Day Delivery Available, otherwise empty.
     *
     * @return list<ProductFilterChangeDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidEnumValueException
     */
    public function getProductsWithChangedShippingOptionsFilters(): array;
}
