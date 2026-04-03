<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Requests;

use App\Domain\ValueObjects\Guid;

/**
 * Structural mapping for the Linnworks GetPurchaseOrdersWithStockItems API endpoint.
 */
final readonly class GetPurchaseOrdersWithStockItemsRequest
{
    /**
     * @param list<string> $locationIds
     */
    private function __construct(
        private string $stockItemId,
        private array $locationIds,
    ) {}

    /**
     * @param list<Guid> $locationIds
     */
    public static function fromResolved(Guid $stockItemId, array $locationIds): self
    {
        return new self(
            stockItemId: $stockItemId->value,
            locationIds: \array_map(
                static fn(Guid $id): string => $id->value,
                $locationIds,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'StockItemId' => $this->stockItemId,
            'LocationIds' => $this->locationIds,
        ];
    }
}
