<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Services;

use App\Application\Contracts\Shopwired\ProductIdentifierResolverInterface;
use App\Domain\Catalog\Product\Exceptions\ProductIdentifierResolutionException;
use App\Infrastructure\Shopwired\Models\ProductModel;
use App\Infrastructure\Shopwired\Models\ProductVariationModel;
use Illuminate\Support\Facades\Log;

/**
 * Resolves product identifiers to ShopWired parent product IDs.
 *
 * Handles both direct product ID verification and SKU lookups,
 * resolving variation SKUs to their parent product IDs.
 */
final readonly class ProductIdentifierResolver implements ProductIdentifierResolverInterface
{
    /**
     * {@inheritDoc}
     *
     * @throws ProductIdentifierResolutionException When SKU or product ID not found
     */
    public function resolveToParentProductId(string|int $identifier): int
    {
        if (\is_int($identifier)) {
            return $this->resolveProductId($identifier);
        }

        return $this->resolveSkuToProductId($identifier);
    }

    /**
     * Verify product exists by external_id and return it.
     *
     * @throws ProductIdentifierResolutionException When product ID not found
     */
    private function resolveProductId(int $productId): int
    {
        $product = ProductModel::query()
            ->where('external_id', $productId)
            ->first(['external_id']);

        if ($product === null) {
            throw ProductIdentifierResolutionException::productIdNotFound($productId);
        }

        return $productId;
    }

    /**
     * Resolve SKU to parent product's ShopWired ID.
     *
     * Searches products first, then variations.
     *
     * @throws ProductIdentifierResolutionException When SKU not found
     */
    private function resolveSkuToProductId(string $sku): int
    {
        // Try product master SKU first
        $product = ProductModel::query()
            ->where('sku', $sku)
            ->first(['external_id']);

        if ($product !== null) {
            return $product->external_id;
        }

        Log::debug('Product not found by SKU, trying variation', ['sku' => $sku]);

        // Try variation SKU - returns parent product ID
        $variation = ProductVariationModel::query()
            ->where('sku', $sku)
            ->first(['product_external_id']);

        if ($variation !== null) {
            return $variation->product_external_id;
        }

        throw ProductIdentifierResolutionException::skuNotFound($sku);
    }
}
