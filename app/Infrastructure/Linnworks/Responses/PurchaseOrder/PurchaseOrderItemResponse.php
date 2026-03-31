<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses\PurchaseOrder;

use App\Domain\Linnworks\ValueObjects\PurchaseOrderItem;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\TaxRate;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks PurchaseOrder item API response DTO.
 *
 * Maps the Get_PurchaseOrder response PurchaseOrderItem array items.
 * Only captures PO-native fields — product enrichment data is ignored
 * (available via StockItem sync).
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class PurchaseOrderItemResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        #[MapInputName('pkPurchaseItemId')]
        public readonly string $pkPurchaseItemId,
        #[MapInputName('fkStockItemId')]
        public readonly string $fkStockItemId,
        public readonly int $quantity,
        public readonly int $delivered,
        public readonly int $packQuantity,
        public readonly int $packSize,
        public readonly float $cost,
        public readonly float $tax,
        public readonly float $taxRate,
        public readonly int $sortOrder,
    ) {}

    public function toDomain(): PurchaseOrderItem
    {
        return new PurchaseOrderItem(
            pkPurchaseItemId: Guid::fromTrusted($this->pkPurchaseItemId),
            fkStockItemId: Guid::fromTrusted($this->fkStockItemId),
            quantity: $this->quantity,
            delivered: $this->delivered,
            packQuantity: $this->packQuantity,
            packSize: $this->packSize,
            cost: $this->cost,
            tax: $this->tax,
            taxRate: TaxRate::fromPercentage($this->taxRate),
            sortOrder: $this->sortOrder,
        );
    }
}
