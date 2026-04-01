<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Contract for looking up product supplier relationships by SKU.
 *
 * Implemented by the lazy-loaded ProductSupplierFactory in Infrastructure.
 */
interface ProductSupplierLookupInterface
{
    /**
     * Get all suppliers for a product by SKU.
     *
     * @return list<ProductSupplier>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getByProductSku(string $sku): array;
}
