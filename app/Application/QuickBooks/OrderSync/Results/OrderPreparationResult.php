<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ReadyOrderDTO;

/**
 * A chunk's orders ready to post, and the outcome of each that will not be.
 */
final readonly class OrderPreparationResult
{
    /**
     * @param list<ReadyOrderDTO>       $ready
     * @param list<OrderSyncOutcomeDTO> $settled
     */
    private function __construct(
        public array $ready,
        public array $settled,
    ) {}

    /**
     * @param list<OrderSyncOutcomeDTO|ReadyOrderDTO> $orders
     */
    public static function of(array $orders): self
    {
        $ready = [];
        $settled = [];
        foreach ($orders as $order) {
            if ($order instanceof ReadyOrderDTO) {
                $ready[] = $order;
            } else {
                $settled[] = $order;
            }
        }

        return new self($ready, $settled);
    }
}
