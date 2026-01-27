<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Product\Exceptions\ProductIdentifierResolutionException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;

/**
 * Resolves product identifiers (SKU or product ID) to ShopWired parent product IDs.
 *
 * Handles both direct product IDs and SKU lookups, resolving variation SKUs
 * to their parent product IDs for API operations.
 *
 * ⚠️ **IMPORTANT: Parent-Level Resolution**
 * This resolver ALWAYS returns the parent product ID, even when given a variation SKU.
 * Any operations using this resolver will affect the product level, not individual
 * variations. ShopWired custom fields are product-level attributes, not variation-level.
 *
 * Example: If SKU "PROD-RED-XL" is a variation of product 12345, this returns 12345.
 * Updates using that ID will modify the parent product's custom fields.
 */
interface ProductIdentifierResolverInterface
{
    /**
     * Resolve identifier to parent product's ShopWired ID.
     *
     * Resolution logic:
     * - int: Verify product exists by external_id, return as-is
     * - string: Search products by SKU, then variations by SKU (returns parent ID)
     *
     * ⚠️ Variation SKUs resolve to their parent product ID, not a variation-specific ID.
     *
     * @param string|int $identifier Product SKU (string) or ShopWired product ID (int)
     *
     * @return int ShopWired parent product ID
     *
     * @throws ProductIdentifierResolutionException When SKU or product ID not found
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function resolveToParentProductId(string|int $identifier): int;
}
