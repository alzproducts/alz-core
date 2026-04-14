<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;

/**
 * Read-model queries against catalog.products_view.
 *
 * Consolidates read-only product queries that consume the pre-computed
 * view rather than the raw shopwired.products table.
 */
interface ProductViewQueryRepositoryInterface
{
    /**
     * Read the current related_products custom field values from local DB.
     *
     * @return array<int, list<IntId>> productExternalId → current related product IntIds
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getCurrentRelatedProducts(): array;
}
