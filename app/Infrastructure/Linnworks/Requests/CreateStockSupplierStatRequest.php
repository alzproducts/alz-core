<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Requests;

use App\Domain\Inventory\ValueObjects\SupplierLinkParams;
use App\Domain\ValueObjects\Guid;

/**
 * Structural mapping for the Linnworks CreateStockSupplierStat API endpoint.
 */
final readonly class CreateStockSupplierStatRequest
{
    private function __construct(
        private string $stockItemId,
        private string $supplierId,
        private float $purchasePrice,
        private string $code,
        private bool $isDefault,
    ) {}

    public static function fromResolved(Guid $stockItemId, SupplierLinkParams $params): self
    {
        return new self(
            stockItemId: $stockItemId->value,
            supplierId: $params->supplierId->value,
            purchasePrice: $params->purchasePrice?->toNet() ?? 0.0,
            code: $params->supplierCode ?? '',
            isDefault: $params->isDefault,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'StockItemId' => $this->stockItemId,
            'SupplierID' => $this->supplierId,
            'PurchasePrice' => $this->purchasePrice,
            'Code' => $this->code,
            'IsDefault' => $this->isDefault,
        ];
    }
}
