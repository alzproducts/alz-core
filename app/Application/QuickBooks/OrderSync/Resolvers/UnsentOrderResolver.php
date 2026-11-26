<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Resolvers;

use App\Application\QuickBooks\OrderSync\DTOs\BuiltOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ClaimedOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ReadyOrderDTO;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;

/**
 * The outcome of each open order that QuickBooks certainly never received: only for what the port itself threw, as
 * repositories and caches throw the same class. Such an order is released for the next attempt, unless it holds an
 * unknown outcome or this is the last attempt.
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
        if ($thrown instanceof QuickBooksDeadlineExceededException) {
            return \array_map(static fn(BuiltOrderDTO $order): OrderSyncOutcomeDTO => OrderSyncOutcomeDTO::released($order->order), $orders);
        }

        return self::unavailable($orders);
    }

    /**
     * @param list<BuiltOrderDTO> $orders
     *
     * @return list<OrderSyncOutcomeDTO>
     */
    public static function unavailable(array $orders): array
    {
        return \array_map(static fn(BuiltOrderDTO $order): OrderSyncOutcomeDTO => self::unsent($order->order, $order->draft->docType()), $orders);
    }

    /**
     * @param list<ReadyOrderDTO> $orders
     *
     * @return list<OrderSyncOutcomeDTO>
     */
    public static function unavailableReady(array $orders): array
    {
        return \array_map(
            static fn(ReadyOrderDTO $order): OrderSyncOutcomeDTO => self::unsent($order->order, $order->document->draft->docType()),
            $orders,
        );
    }

    private static function unsent(ClaimedOrderDTO $order, DocType $docType): OrderSyncOutcomeDTO
    {
        $storedCode = $order->claim->storedCode;
        if ($storedCode === SyncReasonCode::OutcomeUnknown || $order->lastAttempt) {
            return OrderSyncOutcomeDTO::failed($order, SyncOutcomeResolver::forUnavailable($storedCode), $docType);
        }

        return OrderSyncOutcomeDTO::released($order, SyncReasonCode::QboUnavailable);
    }
}
