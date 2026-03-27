<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\Exceptions;

use App\Domain\Exceptions\DomainException;
use App\Domain\Linnworks\Enums\PurchaseOrderStatus;

/**
 * Thrown when attempting an invalid PO status transition.
 *
 * Business rule: PO status transitions are forward-only:
 * PENDING → OPEN → PARTIAL → DELIVERED.
 */
final class InvalidPurchaseOrderStatusTransitionException extends DomainException
{
    public function __construct(
        public readonly PurchaseOrderStatus $from,
        public readonly PurchaseOrderStatus $to,
    ) {
        parent::__construct('Invalid purchase order status transition');
    }

    public function context(): array
    {
        return ['from' => $this->from->value, 'to' => $this->to->value];
    }
}
