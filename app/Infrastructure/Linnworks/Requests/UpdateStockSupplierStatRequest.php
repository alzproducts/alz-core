<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Requests;

use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;

/**
 * Structural mapping for the Linnworks UpdateStockSupplierStat API endpoint.
 *
 * Accepts pre-resolved domain values (UseCase handles all resolution),
 * converts to the wire format expected by Linnworks.
 */
final readonly class UpdateStockSupplierStatRequest
{
    private function __construct(
        private string $stockItemId,
        private string $supplierId,
        private float $purchasePrice,
    ) {}

    public static function fromResolved(string $stockItemId, Guid $supplierGuid, Money $purchasePrice): self
    {
        return new self(
            stockItemId: $stockItemId,
            supplierId: $supplierGuid->value,
            purchasePrice: $purchasePrice->toNet(),
        );
    }

    /**
     * Build the full itemSuppliers payload for a bulk update.
     *
     * @param array<string, Money> $stockItemPrices stockItemId GUID string → purchase price
     *
     * @return list<array{StockItemId: string, SupplierID: string, PurchasePrice: float}>
     */
    public static function buildBulkPayload(Guid $supplierGuid, array $stockItemPrices): array
    {
        $payload = [];

        foreach ($stockItemPrices as $stockItemId => $price) {
            $payload[] = self::fromResolved($stockItemId, $supplierGuid, $price)->toArray();
        }

        return $payload;
    }

    /**
     * @return array{StockItemId: string, SupplierID: string, PurchasePrice: float}
     */
    public function toArray(): array
    {
        return [
            'StockItemId' => $this->stockItemId,
            'SupplierID' => $this->supplierId,
            'PurchasePrice' => $this->purchasePrice,
        ];
    }
}
