<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Application\Catalog\BestSellerLabels\BestSellerLabelChangesResult;
use App\Application\Catalog\DTOs\CreditTierLabelChangeDTO;
use App\Application\Catalog\DTOs\MarginTierAssignmentDTO;
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

    /**
     * Find active products whose custom_label_1 margin-tier label differs from
     * the tier computed from `(net_margin_single_unit_min + _max) / 2` versus
     * `catalog.margin_tier_thresholds`.
     *
     * NULL margin → "4 - Unknown margin". On first run every active product
     * appears in the result (current_label IS NULL, target is non-NULL).
     *
     * @return list<MarginTierAssignmentDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findMarginTierDrift(): array;

    /**
     * Find active products whose custom_label_0 credit-tier label differs from
     * the tier recorded in `catalog.credit_product_popularity_ranking_latest`.
     *
     * Compares latest snapshot's credit_tier against live state in
     * `catalog.products_view`. NULL tier means "no credit sales — clear the label".
     * `NULLIF(custom_fields->>'custom_label_0', '')` normalisation handles the
     * MergesCustomFieldsTrait null→'' write quirk so cleared labels don't
     * re-dispatch forever.
     *
     * @return list<CreditTierLabelChangeDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findCreditTierChanges(): array;

    /**
     * Refresh the catalog.products_view materialized view concurrently.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function refreshMaterializedView(): void;
}
