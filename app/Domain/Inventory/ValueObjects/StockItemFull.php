<?php

declare(strict_types=1);

namespace App\Domain\Inventory\ValueObjects;

use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * Full stock item value object with extended data from GetStockItemsFull endpoint.
 *
 * Contains all fields from the bulk sync endpoint including category information,
 * extended properties, and supplier data. Used for inventory sync and
 * the product_enrichment Mixpanel lookup table.
 *
 * @see StockItem For the simpler version from GetInventoryItemById
 *
 * @template-pattern Domain Value Object
 */
final readonly class StockItemFull
{
    /**
     * @param list<StockItemExtendedProperty> $extendedProperties
     * @param list<StockItemSupplier> $suppliers
     */
    public function __construct(
        public string $stockItemId,
        public string $sku,
        public string $title,
        public string $barcode,
        public int $quantity,
        public int $available,
        public int $inOrder,
        public int $due,
        public int $minimumLevel,
        public bool $jit,
        public float $purchasePrice,
        public float $retailPrice,
        public ?float $taxRate,
        public Weight $weight,
        public Dimensions $dimensions,
        public ?bool $isComposite,
        public string $categoryId,
        public string $categoryName,
        public ?DateTimeImmutable $createdAt = null,
        public array $extendedProperties = [],
        public array $suppliers = [],
    ) {
        Assert::notEmpty($stockItemId, 'Stock item ID cannot be empty');
    }

    /**
     * Check if this stock item has any extended properties.
     */
    public function hasExtendedProperties(): bool
    {
        return $this->extendedProperties !== [];
    }

    /**
     * Get an extended property by name, or null if not found.
     */
    public function getExtendedProperty(string $name): ?StockItemExtendedProperty
    {
        return \array_find(
            $this->extendedProperties,
            static fn(StockItemExtendedProperty $property): bool => $property->name === $name,
        );
    }

    /**
     * Get the value of an extended property by name, or null if not found.
     */
    public function getExtendedPropertyValue(string $name): ?string
    {
        return $this->getExtendedProperty($name)?->value;
    }

    /**
     * Check if this stock item has any suppliers.
     */
    public function hasSuppliers(): bool
    {
        return $this->suppliers !== [];
    }

    /**
     * Get the default supplier, or null if none is marked as default.
     */
    public function getDefaultSupplier(): ?StockItemSupplier
    {
        return \array_find(
            $this->suppliers,
            static fn(StockItemSupplier $supplier): bool => $supplier->isDefault,
        );
    }
}
