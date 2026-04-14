<?php

declare(strict_types=1);

namespace App\Domain\Inventory\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Inventory\Enums\InventoryUpdatableField;
use App\Domain\Shared\Money\ValueObjects\Money;

/**
 * Represents a single field update for a Linnworks inventory item.
 *
 * Use static factory methods for type-safe construction. Domain types are
 * serialised to their API string representation eagerly at construction time,
 * so validation happens immediately and the Infrastructure client stays trivial.
 *
 * API field name mapping lives in Infrastructure (InventoryFieldUpdateClient::mapField()).
 */
final readonly class InventoryFieldUpdate
{
    private function __construct(
        public InventoryUpdatableField $field,
        public string $value,
    ) {}

    public static function category(string $categoryName): self
    {
        return new self(InventoryUpdatableField::Category, $categoryName);
    }

    public static function minimumLevel(int $level): self
    {
        return new self(InventoryUpdatableField::MinimumLevel, (string) $level);
    }

    public static function jit(bool $enabled): self
    {
        return new self(InventoryUpdatableField::JIT, $enabled ? 'true' : 'false');
    }

    public static function retailPrice(Money $price): self
    {
        return new self(InventoryUpdatableField::RetailPrice, (string) $price->toGross());
    }

    public static function purchasePrice(Money $price): self
    {
        return new self(InventoryUpdatableField::PurchasePrice, (string) $price->toNet());
    }

    public static function binRack(string $location): self
    {
        return new self(InventoryUpdatableField::BinRack, $location);
    }

    public static function barcode(Gtin $barcode): self
    {
        return new self(InventoryUpdatableField::Barcode, $barcode->value);
    }

    public static function weight(Weight $weight): self
    {
        return new self(InventoryUpdatableField::Weight, (string) $weight->inKilograms());
    }

    public static function title(string $title): self
    {
        return new self(InventoryUpdatableField::Title, $title);
    }
}
