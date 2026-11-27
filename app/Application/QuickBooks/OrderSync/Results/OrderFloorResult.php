<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

use App\Application\QuickBooks\OrderSync\DTOs\ClaimedOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;

/**
 * The claimed orders on or after the backfill floor, and the outcome of each order placed before it.
 */
final readonly class OrderFloorResult
{
    /**
     * @param list<ClaimedOrderDTO>     $open
     * @param list<OrderSyncOutcomeDTO> $settled
     */
    private function __construct(
        public array $open,
        public array $settled,
    ) {}

    /**
     * @param list<ClaimedOrderDTO|OrderSyncOutcomeDTO> $orders
     */
    public static function of(array $orders): self
    {
        $open = [];
        $settled = [];
        foreach ($orders as $order) {
            if ($order instanceof ClaimedOrderDTO) {
                $open[] = $order;
            } else {
                $settled[] = $order;
            }
        }

        return new self($open, $settled);
    }
}
