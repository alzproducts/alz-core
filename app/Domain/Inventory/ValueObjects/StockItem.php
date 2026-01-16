<?php

declare(strict_types=1);

namespace App\Domain\Inventory\ValueObjects;

use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * Vendor-agnostic stock item value object.
 *
 * Represents inventory data from any source (Linnworks, ShopWired, etc.)
 * in a normalized, business-focused structure. Contains only fields
 * relevant to business logic, not vendor-specific internals.
 *
 * @template-pattern Domain Value Object
 */
final readonly class StockItem
{
    /**
     * @param list<StockItemExtendedProperty> $extendedProperties
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
        public float $purchasePrice,
        public float $retailPrice,
        public ?float $taxRate,
        public Weight $weight,
        public Dimensions $dimensions,
        public bool $isComposite,
        public ?DateTimeImmutable $createdAt = null,
        public array $extendedProperties = [],
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
}
