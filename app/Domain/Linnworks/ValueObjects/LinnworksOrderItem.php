<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;

/**
 * Linnworks order line item value object.
 *
 * Represents a single item within a Linnworks order. Composite sub-items
 * are flattened at the DTO level — they appear as regular items with a
 * non-null `parentItemId` referencing their parent composite item.
 *
 * @template-pattern Domain Value Object
 */
final readonly class LinnworksOrderItem
{
    /**
     * @param list<array{optionId: string, property: string, value: string}> $additionalInfo
     * @param list<array{location: string, binRack: string, batchId: ?int, orderItemBatchId: ?int, quantity: int, addedDate: ?string}> $binRacks
     */
    public function __construct(
        public Guid $rowId,
        public ?Guid $parentItemId,
        public Guid $stockItemId,
        public ?IntId $stockItemIntId,
        public string $itemNumber,
        public string $sku,
        public string $itemSource,
        public string $title,
        public Guid $categoryId,
        public ?string $categoryName,
        public int $quantity,
        public float $pricePerUnit,
        public float $unitCost,
        public float $despatchStockUnitCost,
        public float $discount,
        public float $taxRate,
        public float $cost,
        public float $costIncTax,
        public float $salesTax,
        public bool $taxCostInclusive,
        public float $discountValue,
        public float $weight,
        public ?string $barcodeNumber,
        public string $channelSku,
        public string $channelTitle,
        public bool $batchNumberScanRequired,
        public bool $serialNumberScanRequired,
        public bool $isService,
        public bool $isUnlinked,
        public DateTimeImmutable $addedDate,
        public array $additionalInfo = [],
        public array $binRacks = [],
    ) {}
}
