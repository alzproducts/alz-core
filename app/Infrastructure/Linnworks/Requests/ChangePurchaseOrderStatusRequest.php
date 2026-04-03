<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Requests;

use App\Domain\Linnworks\Enums\PurchaseOrderStatus;
use App\Domain\ValueObjects\Guid;

/**
 * Structural mapping for the Linnworks Change_PurchaseOrderStatus API endpoint.
 */
final readonly class ChangePurchaseOrderStatusRequest
{
    private function __construct(
        private string $purchaseId,
        private string $status,
    ) {}

    public static function fromResolved(Guid $purchaseId, PurchaseOrderStatus $status): self
    {
        return new self(
            purchaseId: $purchaseId->value,
            status: $status->value,
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'pkPurchaseId' => $this->purchaseId,
            'status' => $this->status,
        ];
    }
}
