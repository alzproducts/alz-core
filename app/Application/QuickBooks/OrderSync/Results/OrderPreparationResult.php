<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

use App\Application\QuickBooks\OrderSync\DTOs\BuiltOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;

/**
 * The orders a step left open, and the outcome of each order it settled.
 */
final readonly class OrderPreparationResult
{
    /**
     * @param list<BuiltOrderDTO>       $open
     * @param list<OrderSyncOutcomeDTO> $settled
     */
    private function __construct(
        public array $open,
        public array $settled,
    ) {}

    /**
     * @param list<BuiltOrderDTO|OrderSyncOutcomeDTO> $orders
     */
    public static function of(array $orders): self
    {
        $open = [];
        $settled = [];
        foreach ($orders as $order) {
            if ($order instanceof BuiltOrderDTO) {
                $open[] = $order;
            } else {
                $settled[] = $order;
            }
        }

        return new self($open, $settled);
    }
}
