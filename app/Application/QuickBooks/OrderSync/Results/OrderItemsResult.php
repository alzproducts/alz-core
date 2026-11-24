<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

use App\Application\QuickBooks\OrderSync\DTOs\BuiltOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;

/**
 * The orders still open after the item query, the outcome of each order it settled, and the items it found.
 */
final readonly class OrderItemsResult
{
    /**
     * @param list<BuiltOrderDTO>       $open
     * @param list<OrderSyncOutcomeDTO> $settled
     */
    private function __construct(
        public array $open,
        public array $settled,
        public ?ItemLookupResult $items,
    ) {}

    /**
     * @param list<BuiltOrderDTO|OrderSyncOutcomeDTO> $orders
     * @param ItemLookupResult|null                   $items  kept only when an order is left open
     */
    public static function of(array $orders, ?ItemLookupResult $items): self
    {
        $result = OrderPreparationResult::of($orders);

        return new self($result->open, $result->settled, $result->open === [] ? null : $items);
    }
}
