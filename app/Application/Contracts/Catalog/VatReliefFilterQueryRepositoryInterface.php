<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Application\Catalog\Commands\ProductFilterChangeCommand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface VatReliefFilterQueryRepositoryInterface
{
    /**
     * Find products whose current VAT-relief filter value differs from the desired value.
     *
     * @return list<ProductFilterChangeCommand>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidEnumValueException
     */
    public function getProductsWithChangedVatReliefFilters(): array;
}
