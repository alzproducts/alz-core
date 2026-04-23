<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Models;

use App\Domain\Catalog\Product\ValueObjects\ProductInventory;
use App\Domain\Catalog\Product\ValueObjects\ProductStock;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use App\Infrastructure\Linnworks\Mappers\StockItemModelMapper;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * Eloquent model for linnworks.stock_items table.
 *
 * Stores Linnworks stock items synced from the API. The `stock_item_id` is Linnworks'
 * GUID identifier, while `id` is our internal UUID.
 *
 * @property string $id Internal UUID
 * @property string $stock_item_id Linnworks GUID
 * @property string $item_number SKU
 * @property string $item_title
 * @property string|null $barcode
 * @property int|null $quantity
 * @property int|null $available
 * @property int|null $in_order
 * @property int|null $due
 * @property int|null $minimum_level
 * @property bool $jit
 * @property bool $is_archived
 * @property bool $is_logically_deleted
 * @property float|null $purchase_price
 * @property float|null $retail_price
 * @property float|null $tax_rate
 * @property float|null $weight
 * @property string|null $weight_unit
 * @property float|null $height
 * @property float|null $width
 * @property float|null $depth
 * @property bool $is_composite
 * @property string $category_id Linnworks category GUID
 * @property string $category_name Linnworks category name
 * @property CarbonImmutable|null $linnworks_created_at When created in Linnworks
 * @property CarbonImmutable $created_at When first synced locally
 * @property CarbonImmutable $updated_at When last updated locally
 *
 * @implements EloquentDomainMappableInterface<StockItemFull>
 */
final class StockItemModel extends Model implements EloquentDomainMappableInterface
{
    use HasUuids;

    protected $table = 'linnworks.stock_items';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'available' => 'integer',
            'in_order' => 'integer',
            'due' => 'integer',
            'minimum_level' => 'integer',
            'purchase_price' => 'float',
            'retail_price' => 'float',
            'tax_rate' => 'float',
            'weight' => 'float',
            'height' => 'float',
            'width' => 'float',
            'depth' => 'float',
            ...$this->booleanCasts(),
            ...$this->timestampCasts(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function booleanCasts(): array
    {
        return [
            'jit' => 'boolean',
            'is_composite' => 'boolean',
            'is_archived' => 'boolean',
            'is_logically_deleted' => 'boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function timestampCasts(): array
    {
        return [
            'linnworks_created_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<StockItemExtendedPropertyModel, $this>
     */
    public function extendedProperties(): HasMany
    {
        return $this->hasMany(
            StockItemExtendedPropertyModel::class,
            'stock_item_id',
            'stock_item_id',
        );
    }

    /**
     * @return HasMany<StockItemSupplierModel, $this>
     */
    public function suppliers(): HasMany
    {
        return $this->hasMany(
            StockItemSupplierModel::class,
            'stock_item_id',
            'stock_item_id',
        );
    }

    /**
     * Get the default supplier model, or null if none marked as default.
     *
     * Returns null if the suppliers relation hasn't been loaded.
     */
    public function defaultSupplier(): ?StockItemSupplierModel
    {
        if (! $this->relationLoaded('suppliers')) {
            return null;
        }

        return $this->suppliers->first(
            static fn(StockItemSupplierModel $s): bool => $s->is_default,
        );
    }

    /** Project this stock item as a catalog inventory enrichment VO. */
    public function toProductInventory(): ProductInventory
    {
        return new ProductInventory(
            barcode: $this->barcode,
            minimumLevel: $this->minimum_level,
            weight: $this->weight,
            weightUnit: $this->weight_unit,
            height: $this->height,
            width: $this->width,
            depth: $this->depth,
            isComposite: $this->is_composite,
            categoryName: $this->category_name,
        );
    }

    /**
     * Build a catalog stock-level VO from an optional master record plus aggregate values.
     *
     * $master may be null when the product tracks stock only at the variation level — in
     * that case the master-level fields (quantity, available, in_order, due) remain null
     * and callers rely on the aggregate values.
     */
    public static function buildProductStock(
        ?self $master,
        int $aggregateAvailable,
        int $aggregatePhysical,
    ): ProductStock {
        return new ProductStock(
            quantity: $master?->quantity,
            available: $master?->available,
            inOrder: $master?->in_order,
            due: $master?->due,
            jit: $master !== null && $master->jit,
            aggregateAvailable: $aggregateAvailable,
            aggregatePhysical: $aggregatePhysical,
        );
    }

    public function toDomain(): StockItemFull
    {
        return StockItemModelMapper::fromModel($this);
    }

    /**
     * @param StockItemFull $entity
     *
     * @return array<string, mixed>
     */
    public static function fromDomainAttributes(object $entity): array
    {
        return StockItemModelMapper::toModelAttributes($entity);
    }
}
