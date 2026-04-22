<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Application\Catalog\Commands\ProductFilterChangeCommand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface ShippingOffersFilterQueryRepositoryInterface
{
    /**
     * Find products whose current "Shipping Offers" filter entry differs from the desired state.
     *
     * Each row's `desiredFilterValues` is derived from the `free_delivery` custom field:
     * `Standard` → Free Standard Delivery, `Express` → Free Express Delivery, otherwise empty.
     *
     * @return list<ProductFilterChangeCommand>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidEnumValueException
     */
    public function getProductsWithChangedShippingOffersFilters(): array;
}
