<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Domain\Inventory\Enums\WeightUnit;
use App\Domain\Inventory\ValueObjects\Dimensions;
use App\Domain\Inventory\ValueObjects\StockItemExtendedProperty;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\Inventory\ValueObjects\StockItemSupplier;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks stock item API response DTO for GetStockItemsFull endpoint.
 *
 * This endpoint returns a different structure than GetInventoryItemById:
 * - Extended properties are in `ItemExtendedProperties` (not `ExtendedProperties`)
 * - Stock levels are in nested `StockLevels` array (per-location)
 * - Pricing fields appear when `Pricing` is in dataRequirements
 *
 * @see https://apidocs.linnworks.net/reference/getstockitemsfull
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class StockItemFullResponse extends Data implements DomainConvertibleInterface
{
    /**
     * @param list<StockLevelResponse> $stockLevels Per-location stock levels
     * @param list<ExtendedPropertyResponse> $itemExtendedProperties API returns ItemExtendedProperties
     * @param list<StockItemSupplierResponse> $suppliers Supplier relationships for this item
     */
    public function __construct(
        public readonly string $stockItemId,
        public readonly int $stockItemIntId,
        public readonly string $itemNumber,
        public readonly string $itemTitle,
        public readonly string $barcodeNumber,
        public readonly float $purchasePrice,
        public readonly float $retailPrice,
        public readonly float $taxRate,
        public readonly float $weight,
        public readonly float $height,
        public readonly float $width,
        public readonly float $depth,
        public readonly string $categoryId,
        public readonly string $categoryName,
        public readonly bool $isBatchedStockType,
        public readonly int $inventoryTrackingType,
        public readonly ?bool $isVariationParent = null, // Not returned by GetStockItemsFullByIds
        public readonly ?string $creationDate = null,
        #[DataCollectionOf(StockLevelResponse::class)]
        public readonly array $stockLevels = [],
        #[DataCollectionOf(ExtendedPropertyResponse::class)]
        public readonly array $itemExtendedProperties = [],
        #[DataCollectionOf(StockItemSupplierResponse::class)]
        public readonly array $suppliers = [],
        public readonly ?bool $isCompositeParent = null,
    ) {}

    public function toDomain(): StockItemFull
    {
        $defaultStock = $this->findDefaultLocationStock();

        return new StockItemFull(
            stockItemId: $this->stockItemId,
            sku: $this->itemNumber,
            title: $this->itemTitle,
            barcode: $this->barcodeNumber,
            quantity: $defaultStock !== null ? $defaultStock->stockLevel : 0,
            available: $defaultStock !== null ? $defaultStock->available : 0,
            inOrder: $defaultStock !== null ? $defaultStock->inOrders : 0,
            due: $defaultStock !== null ? $defaultStock->due : 0,
            minimumLevel: $defaultStock !== null ? $defaultStock->minimumLevel : 0,
            jit: $defaultStock !== null ? $defaultStock->jit : false,
            purchasePrice: $this->purchasePrice,
            retailPrice: $this->retailPrice,
            taxRate: $this->taxRate < 0 ? null : $this->taxRate, // -1 means "use default"
            weight: new Weight($this->weight, WeightUnit::Kilogram),
            dimensions: new Dimensions($this->height, $this->width, $this->depth),
            isComposite: $this->isCompositeParent, // null when GetStockItemsFull (field absent), bool when GetStockItemsFullByIds
            categoryId: $this->categoryId,
            categoryName: $this->categoryName,
            createdAt: $this->creationDate !== null
                ? CarbonImmutable::parse($this->creationDate)->toDateTimeImmutable()
                : null,
            extendedProperties: $this->mapExtendedProperties(),
            suppliers: $this->mapSuppliers(),
        );
    }

    /**
     * Find stock level entry for the default location.
     *
     * Loops through StockLevels to find the entry with the default location ID
     * (00000000-0000-0000-0000-000000000000). Usually first, but not guaranteed.
     */
    private function findDefaultLocationStock(): ?StockLevelResponse
    {
        return \array_find(
            $this->stockLevels,
            static fn(StockLevelResponse $level): bool => $level->isDefaultLocation(),
        );
    }

    /**
     * Map extended properties to domain value objects.
     *
     * @return list<StockItemExtendedProperty>
     */
    private function mapExtendedProperties(): array
    {
        return \array_map(
            static fn(ExtendedPropertyResponse $ep): StockItemExtendedProperty => $ep->toDomain(),
            $this->itemExtendedProperties,
        );
    }

    /**
     * Map suppliers to domain value objects.
     *
     * @return list<StockItemSupplier>
     */
    private function mapSuppliers(): array
    {
        return \array_map(
            static fn(StockItemSupplierResponse $supplier): StockItemSupplier => $supplier->toDomain(),
            $this->suppliers,
        );
    }
}
