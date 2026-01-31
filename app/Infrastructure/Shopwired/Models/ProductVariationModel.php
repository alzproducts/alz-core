<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Models;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for shopwired.product_variations table.
 *
 * Stores product variations synced from ShopWired API.
 * Uses composite unique key (product_external_id, external_id) for stable sync.
 *
 * @property string $id Internal UUID
 * @property string $product_id Parent product UUID (FK relationship)
 * @property int $product_external_id Parent product's ShopWired ID (stable sync key)
 * @property int $external_id ShopWired variation ID
 * @property string|null $sku Variation SKU
 * @property float|null $price Selling price (null = inherit parent, 0.00 = removed from sale)
 * @property float|null $cost_price Cost/wholesale price (-1.0 = inherit parent, 0.00 = unknown, >0 = valid)
 * @property float|null $sale_price Discounted price
 * @property int $stock Stock quantity
 * @property float|null $weight Weight in configured unit
 * @property string|null $gtin Global Trade Item Number (barcode)
 * @property string|null $mpn Manufacturer Part Number
 * @property int|null $image_index Index into parent product's images array
 * @property list<array{option_id: int, option_name: string, value_id: int, value_name: string}> $options Option attributes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 *
 * @implements EloquentDomainMappableInterface<ProductVariation>
 */
final class ProductVariationModel extends Model implements EloquentDomainMappableInterface
{
    use HasUuids;

    protected $table = 'shopwired.product_variations';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Money fields - cast to float to match Domain types
            'price' => 'float',
            'cost_price' => 'float',
            'sale_price' => 'float',
            'weight' => 'float',
            // JSON
            'options' => 'array',
            // Timestamps
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the parent product.
     *
     * @return BelongsTo<ProductModel, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'product_id', 'id');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Domain Mapping (manual - has complex transformations)
    // ─────────────────────────────────────────────────────────────────────────

    public function toDomain(): ProductVariation
    {
        return new ProductVariation(
            id: $this->external_id,
            productExternalId: $this->product_external_id,
            sku: $this->sku,
            price: $this->price,
            costPrice: $this->cost_price,
            salePrice: $this->sale_price,
            stock: $this->stock,
            weight: $this->weight,
            gtin: $this->gtin !== null ? Gtin::fromTrusted($this->gtin) : null,
            mpn: $this->mpn,
            imageIndex: $this->image_index,
            options: $this->buildOptions(),
        );
    }

    /**
     * @param ProductVariation $entity
     *
     * @return array<string, mixed>
     */
    public static function fromDomainAttributes(object $entity): array
    {
        return [
            'product_external_id' => $entity->productExternalId,
            'external_id' => $entity->id,
            'sku' => $entity->sku,
            'price' => $entity->price,
            'cost_price' => $entity->costPrice,
            'sale_price' => $entity->salePrice,
            'stock' => $entity->stock,
            'weight' => $entity->weight,
            'gtin' => $entity->gtin?->value,
            'mpn' => $entity->mpn,
            'image_index' => $entity->imageIndex,
            'options' => \array_map(
                static fn(ProductVariationOption $opt): array => $opt->toArray(),
                $entity->options,
            ),
        ];
    }

    /**
     * Convert DB options arrays to ProductVariationOption objects.
     *
     * @return list<ProductVariationOption>
     */
    private function buildOptions(): array
    {
        return \array_map(
            static fn(array $opt): ProductVariationOption => ProductVariationOption::fromArray($opt),
            $this->options,
        );
    }
}
