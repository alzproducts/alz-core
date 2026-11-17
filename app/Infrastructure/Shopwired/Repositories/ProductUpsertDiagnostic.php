<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Infrastructure\Catalog\Product\Models\ProductModel;
use Illuminate\Support\Facades\Log;

/**
 * intentional: temporary stale-price diagnostic for product upserts, delete once COR-279 records its findings.
 */
final readonly class ProductUpsertDiagnostic
{
    /** Microseconds, so the log shows whether ShopWired's updatedAt is finer than whole seconds. */
    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * Must run inside the upsert's transaction, before the upsert.
     */
    public static function lockStored(int $externalId): ?ProductModel
    {
        // intentional: row lock so a concurrent writer cannot commit between this read and the upsert (COR-279 P1)
        return ProductModel::query()
            ->where('external_id', $externalId)
            ->lockForUpdate()
            ->first(['price', 'sale_price', 'shopwired_updated_at']);
    }

    /**
     * @param 'api'|'webhook' $source
     */
    public static function log(Product $product, string $source, ?ProductModel $replaced, bool $variationsSynced): void
    {
        Log::info('Product upsert diagnostic', [
            'product_id' => $product->id,
            'source' => $source,
            'incoming' => [
                'price' => $product->price,
                'sale_price' => $product->salePrice,
                'updated_at' => $product->updatedAt->format(self::TIME_FORMAT),
            ],
            'replaced' => [
                'price' => $replaced?->price,
                'sale_price' => $replaced?->sale_price,
                'shopwired_updated_at' => $replaced?->shopwired_updated_at->format(self::TIME_FORMAT),
            ],
            'variations_synced' => $variationsSynced,
            'variations' => $variationsSynced ? self::variations($product) : null,
        ]);
    }

    /**
     * @return list<array{id: int, sku: string|null, price: float|null, sale_price: float|null}>
     */
    private static function variations(Product $product): array
    {
        return \array_map(static fn(ProductVariation $v): array => [
            'id' => $v->id,
            'sku' => $v->sku,
            'price' => $v->price,
            'sale_price' => $v->salePrice,
        ], $product->variations ?? []);
    }
}
