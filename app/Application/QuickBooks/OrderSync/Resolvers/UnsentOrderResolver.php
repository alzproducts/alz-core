<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Resolvers;

use App\Application\QuickBooks\OrderSync\DTOs\BuiltOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;

/**
 * The outcome of each open order when a QuickBooks call before the batch create stops them: only for what the port
 * itself threw, as repositories and caches throw the same class.
 */
final class UnsentOrderResolver
{
    /**
     * @param list<BuiltOrderDTO> $orders
     *
     * @return list<OrderSyncOutcomeDTO>
     */
    public static function outcomes(array $orders, ExternalServiceUnavailableException|QuickBooksDeadlineExceededException $thrown): array
    {
        return \array_map(
            static fn(BuiltOrderDTO $order): OrderSyncOutcomeDTO => $thrown instanceof QuickBooksDeadlineExceededException
                ? OrderSyncOutcomeDTO::released($order->order)
                : OrderSyncOutcomeDTO::failed(
                    $order->order,
                    SyncOutcomeResolver::forUnavailable($order->order->claim->storedCode),
                    $order->draft->docType(),
                ),
            $orders,
        );
    }
}
