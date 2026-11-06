<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Queries\ProductList;

use App\Domain\Catalog\Product\ValueObjects\ProductInventory;
use App\Domain\Catalog\Product\ValueObjects\ProductStock;

/**
 * The Linnworks stock item matched to a SKU, as aggregated by {@see ProductListSql::STOCK_ITEM_FIELDS}.
 *
 * @internal
 */
final readonly class ProductListStockItemRow
{
    public ?string $barcode;

    public ?int $minimumLevel;

    public ?float $weight;

    public ?string $weightUnit;

    public ?float $height;

    public ?float $width;

    public ?float $depth;

    public bool $isComposite;

    public string $categoryName;

    public ?int $quantity;

    public ?int $available;

    public ?int $inOrder;

    public ?int $due;

    public bool $jit;

    public ?ProductListSupplierRow $defaultSupplier;

    /**
     * @param array<string, mixed> $row
     */
    public function __construct(array $row)
    {
        $this->barcode = ProductListValue::nullableString($row, 'barcode');
        $this->minimumLevel = ProductListValue::nullableInt($row, 'minimum_level');
        $this->weight = ProductListValue::nullableFloat($row, 'weight');
        $this->weightUnit = ProductListValue::nullableString($row, 'weight_unit');
        $this->height = ProductListValue::nullableFloat($row, 'height');
        $this->width = ProductListValue::nullableFloat($row, 'width');
        $this->depth = ProductListValue::nullableFloat($row, 'depth');
        $this->isComposite = ProductListValue::bool($row, 'is_composite');
        $this->categoryName = ProductListValue::string($row, 'category_name');
        $this->quantity = ProductListValue::nullableInt($row, 'quantity');
        $this->available = ProductListValue::nullableInt($row, 'available');
        $this->inOrder = ProductListValue::nullableInt($row, 'in_order');
        $this->due = ProductListValue::nullableInt($row, 'due');
        $this->jit = ProductListValue::bool($row, 'jit');

        $supplier = ProductListValue::nullableObject($row, 'default_supplier');
        $this->defaultSupplier = $supplier !== null ? new ProductListSupplierRow($supplier) : null;
    }

    public function toProductInventory(): ProductInventory
    {
        return new ProductInventory(
            barcode: $this->barcode,
            minimumLevel: $this->minimumLevel === null || $this->minimumLevel < 0 ? null : $this->minimumLevel, // -1 means "not set"
            weight: $this->weight,
            weightUnit: $this->weightUnit,
            height: $this->height,
            width: $this->width,
            depth: $this->depth,
            isComposite: $this->isComposite,
            categoryName: $this->categoryName,
        );
    }

    public static function buildProductStock(?self $master, int $aggregateAvailable, int $aggregatePhysical): ProductStock
    {
        return new ProductStock(
            quantity: $master?->quantity,
            available: $master?->available,
            inOrder: $master?->inOrder,
            due: $master?->due,
            jit: $master !== null && $master->jit,
            aggregateAvailable: $aggregateAvailable,
            aggregatePhysical: $aggregatePhysical,
        );
    }
}
