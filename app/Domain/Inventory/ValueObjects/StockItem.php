<?php

declare(strict_types=1);

namespace App\Domain\Inventory\ValueObjects;

use DateTimeImmutable;

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
    public function __construct(
        public string $sku,
        public string $title,
        public string $description,
        public string $barcode,
        public int $quantity,
        public int $available,
        public int $inOrder,
        public int $due,
        public int $minimumLevel,
        public float $purchasePrice,
        public float $retailPrice,
        public float $taxRate,
        public ?float $weight,
        public float $height,
        public float $width,
        public float $depth,
        public string $categoryName,
        public ?DateTimeImmutable $createdAt,
        public bool $isComposite,
    ) {}
}
