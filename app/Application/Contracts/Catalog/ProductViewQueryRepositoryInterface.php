<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Application\Catalog\BestSellerLabels\BestSellerLabelChangesResult;
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

    /**
     * Find products whose custom_label_4 Best Sellers label needs to change.
     *
     * To-add:    popularity_rank <= 2 AND "Best Sellers" NOT in custom_label_4
     * To-remove: (popularity_rank IS NULL OR popularity_rank > 2)
     *            AND "Best Sellers" IS in custom_label_4
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findBestSellerLabelChanges(): BestSellerLabelChangesResult;
}
