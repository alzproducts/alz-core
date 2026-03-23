<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;

/**
 * Persists and retrieves sale settings for products in a sale.
 *
 * One row per product. Rows are upserted when a product is added to sale
 * and deleted when removed. Enables AddToSaleJob to read fresh settings
 * at execution time rather than relying on stale serialized job payload data.
 */
interface SaleSettingsRepositoryInterface
{
    /**
     * Persist sale settings for a product (upsert — safe to call repeatedly).
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database unavailable
     */
    public function save(IntId $productId, SaleSettings $settings): void;

    /**
     * Retrieve persisted sale settings for a product, or null if not found.
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database unavailable
     */
    public function findByProduct(IntId $productId): ?SaleSettings;

    /**
     * Delete sale settings for a product.
     *
     * No-op if no row exists (safe to call for non-sale products).
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database unavailable
     */
    public function delete(IntId $productId): void;
}
