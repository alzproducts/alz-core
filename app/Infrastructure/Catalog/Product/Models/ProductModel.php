<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Models;

use App\Domain\Catalog\Product\ValueObjects\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Eloquent model for shopwired.products table.
 *
 * Stores ShopWired products synced from the API. The `external_id` is ShopWired's
 * product ID, while `id` is our internal UUID (never exposed to Domain layer).
 *
 * **Domain Conversion**: Use ProductModelMapper for all model ↔ domain conversion.
 * This model intentionally does NOT implement EloquentDomainMappableInterface because
 * Product requires custom field typing via ProductCustomFieldFactory, which the mapper handles.
 *
 * @property string $id Internal UUID
 * @property int $external_id ShopWired product ID
 * @property string|null $sku Master SKU
 * @property string $title Product title
 * @property string|null $description HTML description
 * @property string $slug URL slug
 * @property string $url Full product URL
 * @property float $price Selling price
 * @property float|null $cost_price Cost/wholesale price
 * @property float|null $sale_price Discounted price
 * @property float|null $compare_price RRP / "Was" price
 * @property int|null $stock Master stock quantity
 * @property bool $is_active Published/visible
 * @property bool $vat_exclusive Price excludes VAT
 * @property bool|null $vat_relief VAT relief eligible (null = unknown, awaiting full sync)
 * @property float|null $weight Weight in configured unit
 * @property int|null $sort_order ShopWired sort order
 * @property string|null $meta_title SEO title
 * @property string|null $meta_description SEO description
 * @property string|null $gtin Global Trade Item Number (barcode)
 * @property list<int> $category_ids ShopWired category IDs
 * @property list<array{id: int, url: string, description: string|null, sort_order: int}> $images Product images
 * @property array<string, mixed> $custom_fields Raw custom fields from API
 * @property array<int|string, list<string>> $filters Raw filter data from API
 * @property CarbonImmutable $shopwired_created_at ShopWired creation timestamp
 * @property CarbonImmutable $shopwired_updated_at ShopWired last update timestamp
 * @property CarbonImmutable $created_at When first synced to local DB
 * @property CarbonImmutable $updated_at When last updated locally
 */
final class ProductModel extends Model
{
    use HasUuids;

    protected $table = 'shopwired.products';

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
            'compare_price' => 'float',
            'weight' => 'float',
            // JSON
            'category_ids' => 'array',
            'images' => 'array',
            'custom_fields' => 'array',
            'filters' => 'array',
            // Booleans
            'is_active' => 'boolean',
            'vat_exclusive' => 'boolean',
            'vat_relief' => 'boolean',
            // Timestamps
            'shopwired_created_at' => 'immutable_datetime',
            'shopwired_updated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the product's variations.
     *
     * @return HasMany<ProductVariationModel, $this>
     */
    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariationModel::class, 'product_id', 'id');
    }

    /**
     * Get the product's extra data (RRP, etc.) matched by SKU.
     *
     * @return HasOne<ProductExtraDataModel, $this>
     */
    public function extraData(): HasOne
    {
        return $this->hasOne(ProductExtraDataModel::class, 'sku', 'sku');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Domain → Attributes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert Domain Product to core scalar attributes (no embeds, no PK).
     *
     * Embed-dependent columns (vat_relief, category_ids, images, custom_fields,
     * filters) are written by the mapper — full save always, webhook save only
     * when the embed was present in the payload.
     *
     * @return array<string, mixed>
     */
    public static function attributesFromDomain(Product $product): array
    {
        return [
            'external_id' => $product->id,
            'sku' => $product->sku,
            'gtin' => $product->gtin?->value,
            'title' => $product->title,
            'description' => $product->description,
            'slug' => $product->slug,
            'url' => $product->url,
            'price' => $product->price,
            'cost_price' => $product->costPrice,
            'sale_price' => $product->salePrice,
            'compare_price' => $product->comparePrice,
            'stock' => $product->stock,
            'is_active' => $product->isActive,
            'vat_exclusive' => $product->vatExclusive,
            'weight' => $product->weight,
            'meta_title' => $product->metaTitle,
            'meta_description' => $product->metaDescription,
            'sort_order' => $product->sortOrder,
            'shopwired_created_at' => $product->createdAt,
            'shopwired_updated_at' => $product->updatedAt,
        ];
    }
}
