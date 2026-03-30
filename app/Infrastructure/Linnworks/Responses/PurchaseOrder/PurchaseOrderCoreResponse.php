<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses\PurchaseOrder;

use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderAdditionalCost;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderCore;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderDeliveredRecord;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderItem;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Composite response DTO for the Get_PurchaseOrder endpoint.
 *
 * Maps ALL data returned by a single Get_PurchaseOrder call: the nested
 * PurchaseOrderHeader object, note count, and child arrays (items,
 * additional costs, delivered records).
 *
 * The existing getPurchaseOrder() discards the child arrays — this response
 * captures everything for the two-speed sync strategy.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class PurchaseOrderCoreResponse extends Data implements DomainConvertibleInterface
{
    /**
     * @param list<PurchaseOrderItemResponse>|null            $purchaseOrderItem
     * @param list<PurchaseOrderAdditionalCostResponse>|null  $additionalCosts
     * @param list<PurchaseOrderDeliveredRecordResponse>|null $deliveredRecords
     */
    public function __construct(
        // ── Header (nested object) ──
        public readonly PurchaseOrderHeaderResponse $purchaseOrderHeader,

        // ── Core extras ──
        public readonly int $noteCount,

        // ── Child collections ──
        #[DataCollectionOf(PurchaseOrderItemResponse::class)]
        #[MapInputName('PurchaseOrderItem')]
        public readonly ?array $purchaseOrderItem = null,
        #[DataCollectionOf(PurchaseOrderAdditionalCostResponse::class)]
        #[MapInputName('AdditionalCosts')]
        public readonly ?array $additionalCosts = null,
        #[DataCollectionOf(PurchaseOrderDeliveredRecordResponse::class)]
        #[MapInputName('DeliveredRecords')]
        public readonly ?array $deliveredRecords = null,
    ) {}

    /**
     * @throws InvalidApiResponseException When status or date parsing fails
     */
    public function toDomain(): PurchaseOrderCore
    {
        return new PurchaseOrderCore(
            header: $this->purchaseOrderHeader->toDomain(),
            noteCount: $this->noteCount,
            items: $this->mapItems(),
            additionalCosts: $this->mapAdditionalCosts(),
            deliveredRecords: $this->mapDeliveredRecords(),
        );
    }

    /**
     * @return list<PurchaseOrderItem>
     *
     * @throws InvalidApiResponseException
     */
    private function mapItems(): array
    {
        return $this->purchaseOrderItem !== null
            ? \array_map(static fn(PurchaseOrderItemResponse $r): PurchaseOrderItem => $r->toDomain(), $this->purchaseOrderItem)
            : [];
    }

    /**
     * @return list<PurchaseOrderAdditionalCost>
     */
    private function mapAdditionalCosts(): array
    {
        return $this->additionalCosts !== null
            ? \array_map(static fn(PurchaseOrderAdditionalCostResponse $r): PurchaseOrderAdditionalCost => $r->toDomain(), $this->additionalCosts)
            : [];
    }

    /**
     * @return list<PurchaseOrderDeliveredRecord>
     *
     * @throws InvalidApiResponseException
     */
    private function mapDeliveredRecords(): array
    {
        return $this->deliveredRecords !== null
            ? \array_map(static fn(PurchaseOrderDeliveredRecordResponse $r): PurchaseOrderDeliveredRecord => $r->toDomain(), $this->deliveredRecords)
            : [];
    }
}
