<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Money\ValueObjects\Money;

/**
 * Repository for per-SKU extra product data (RRP, etc.).
 */
interface ProductExtraDataRepositoryInterface
{
    /**
     * Upsert RRP for a SKU. Null clears the RRP value.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function upsertRrp(Sku $sku, ?Money $rrp): void;
}
