<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses\PurchaseOrder;

use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderDeliveredRecord;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\LinnworksDateParser;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks PurchaseOrder delivered record API response DTO.
 *
 * Maps the Get_PurchaseOrder response DeliveredRecords array items.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class PurchaseOrderDeliveredRecordResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly int $pkDeliveryRecordId,
        #[MapInputName('fkPurchaseItemId')]
        public readonly string $fkPurchaseItemId,
        #[MapInputName('fkStockLocationId')]
        public readonly string $fkStockLocationId,
        public readonly float $unitCost,
        public readonly int $deliveredQuantity,
        public readonly ?string $createdDateTime,
    ) {}

    /**
     * @throws InvalidApiResponseException When date parsing fails
     */
    public function toDomain(): PurchaseOrderDeliveredRecord
    {
        return new PurchaseOrderDeliveredRecord(
            pkDeliveryRecordId: IntId::fromTrusted($this->pkDeliveryRecordId),
            fkPurchaseItemId: Guid::fromTrusted($this->fkPurchaseItemId),
            fkStockLocationId: Guid::fromTrusted($this->fkStockLocationId),
            unitCost: $this->unitCost,
            deliveredQuantity: $this->deliveredQuantity,
            createdDateTime: LinnworksDateParser::parse($this->createdDateTime),
        );
    }
}
