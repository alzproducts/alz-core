<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses\PurchaseOrder;

use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderCore;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderItem;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Composite response DTO for the Get_PurchaseOrder endpoint.
 *
 * Maps the Core subset: header, note count, and items.
 * Additional costs and delivered records are parsed separately
 * for the PurchaseOrderDepth::Full read path.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class PurchaseOrderCoreResponse extends Data implements DomainConvertibleInterface
{
    /**
     * @param list<PurchaseOrderItemResponse>|null $purchaseOrderItem
     */
    public function __construct(
        public readonly PurchaseOrderHeaderResponse $purchaseOrderHeader,
        public readonly int $noteCount,
        #[DataCollectionOf(PurchaseOrderItemResponse::class)]
        #[MapInputName('PurchaseOrderItem')]
        public readonly ?array $purchaseOrderItem = null,
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
}
