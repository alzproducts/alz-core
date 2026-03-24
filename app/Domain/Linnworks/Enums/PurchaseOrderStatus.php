<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\Enums;

/**
 * Linnworks purchase order lifecycle status.
 *
 * Encapsulates the allowed state transitions as a business rule:
 * PENDING → OPEN → PARTIAL → DELIVERED (forward-only, no skipping PARTIAL from PENDING).
 *
 * @template-pattern Domain Enum
 */
enum PurchaseOrderStatus: string
{
    case Pending = 'PENDING';
    case Open = 'OPEN';
    case Partial = 'PARTIAL';
    case Delivered = 'DELIVERED';

    /**
     * Get the statuses this status can transition to.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Open],
            self::Open => [self::Partial, self::Delivered],
            self::Partial => [self::Delivered],
            self::Delivered => [],
        };
    }

    /**
     * Check whether transitioning to the target status is allowed.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => $target === self::Open,
            self::Open => $target === self::Partial || $target === self::Delivered,
            self::Partial => $target === self::Delivered,
            self::Delivered => false,
        };
    }
}
