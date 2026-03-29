<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Domain\Linnworks\ValueObjects\LinnworksOrderItem;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * DTO for order item from the v2 GetOrders endpoint.
 *
 * Composite sub-items are recursively flattened in toDomain() —
 * each sub-item becomes a regular LinnworksOrderItem with its
 * parentItemId set to the composite parent's RowId.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class OrderItemResponse extends Data
{
    /**
     * @param list<OrderItemAdditionalInfoResponse>|null $additionalInfo
     * @param list<OrderItemBinRackResponse>|null $binRack
     * @param list<OrderItemResponse>|null $compositeSubItems
     */
    public function __construct(
        public readonly string $rowId,
        public readonly string $stockItemId,
        public readonly int $stockItemIntId,
        public readonly string $itemNumber,
        #[MapInputName('SKU')]
        public readonly string $sku,
        public readonly string $itemSource,
        public readonly string $title,
        public readonly string $categoryId,
        public readonly ?string $categoryName,
        public readonly int $quantity,
        public readonly float $pricePerUnit,
        public readonly float $unitCost,
        public readonly float $despatchStockUnitCost,
        public readonly float $discount,
        public readonly float $taxRate,
        public readonly float $cost,
        public readonly float $costIncTax,
        public readonly float $salesTax,
        public readonly bool $taxCostInclusive,
        public readonly float $discountValue,
        public readonly float $weight,
        public readonly ?string $barcodeNumber,
        #[MapInputName('ChannelSKU')]
        public readonly string $channelSku,
        public readonly string $channelTitle,
        public readonly bool $batchNumberScanRequired,
        public readonly bool $serialNumberScanRequired,
        public readonly bool $isService,
        public readonly bool $isUnlinked,
        public readonly string $addedDate,
        #[DataCollectionOf(OrderItemAdditionalInfoResponse::class)]
        public readonly ?array $additionalInfo = null,
        #[DataCollectionOf(OrderItemBinRackResponse::class)]
        public readonly ?array $binRack = null,
        #[DataCollectionOf(self::class)]
        public readonly ?array $compositeSubItems = null,
        public readonly ?string $parentItemId = null,
    ) {}

    /**
     * Convert to domain value objects, flattening composite sub-items.
     *
     * Each composite sub-item is recursively flattened into a regular
     * LinnworksOrderItem with parentItemId set to its parent's RowId.
     *
     * @return list<LinnworksOrderItem>
     */
    public function toDomain(): array
    {
        $item = new LinnworksOrderItem(
            rowId: new Guid($this->rowId),
            parentItemId: $this->parentItemId !== null ? new Guid($this->parentItemId) : null,
            stockItemId: new Guid($this->stockItemId),
            stockItemIntId: $this->stockItemIntId > 0 ? IntId::from($this->stockItemIntId) : null,
            itemNumber: $this->itemNumber,
            sku: $this->sku,
            itemSource: $this->itemSource,
            title: $this->title,
            categoryId: new Guid($this->categoryId),
            categoryName: $this->categoryName,
            quantity: $this->quantity,
            pricePerUnit: $this->pricePerUnit,
            unitCost: $this->unitCost,
            despatchStockUnitCost: $this->despatchStockUnitCost,
            discount: $this->discount,
            taxRate: $this->taxRate,
            cost: $this->cost,
            costIncTax: $this->costIncTax,
            salesTax: $this->salesTax,
            taxCostInclusive: $this->taxCostInclusive,
            discountValue: $this->discountValue,
            weight: $this->weight,
            barcodeNumber: $this->barcodeNumber,
            channelSku: $this->channelSku,
            channelTitle: $this->channelTitle,
            batchNumberScanRequired: $this->batchNumberScanRequired,
            serialNumberScanRequired: $this->serialNumberScanRequired,
            isService: $this->isService,
            isUnlinked: $this->isUnlinked,
            addedDate: CarbonImmutable::parse($this->addedDate)->toDateTimeImmutable(),
            additionalInfo: $this->mapAdditionalInfo(),
            binRacks: $this->mapBinRacks(),
        );

        $items = [$item];

        foreach ($this->compositeSubItems ?? [] as $subItem) {
            \array_push($items, ...$subItem->toDomain());
        }

        return $items;
    }

    /**
     * @return list<array{optionId: string, property: string, value: string}>
     */
    private function mapAdditionalInfo(): array
    {
        $result = [];

        foreach ($this->additionalInfo ?? [] as $ai) {
            $result[] = $ai->toArray();
        }

        return $result;
    }

    /**
     * @return list<array{location: string, binRack: string, batchId: ?int, orderItemBatchId: ?int, quantity: int, addedDate: ?string}>
     */
    private function mapBinRacks(): array
    {
        $result = [];

        foreach ($this->binRack ?? [] as $br) {
            $result[] = $br->toArray();
        }

        return $result;
    }
}
