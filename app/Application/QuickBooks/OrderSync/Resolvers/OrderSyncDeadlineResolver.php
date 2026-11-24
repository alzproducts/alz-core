<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Resolvers;

use App\Application\QuickBooks\OrderSync\DTOs\ClaimedOrderDTO;
use App\Domain\Shared\ValueObjects\Deadline;

/**
 * The latest moment a chunk's QuickBooks calls may run to. A timed-out batch can still post for about 120 s, so calls
 * end long enough before the earliest lease that no other worker can re-claim the row meanwhile.
 */
final class OrderSyncDeadlineResolver
{
    private const int LEASE_MARGIN_SECONDS = 150;

    /**
     * @param list<ClaimedOrderDTO> $claimed
     */
    public static function resolve(array $claimed, Deadline $caller): Deadline
    {
        return \array_reduce(
            $claimed,
            static fn(Deadline $earliest, ClaimedOrderDTO $order): Deadline
                => $earliest->earlier(Deadline::before($order->claim->leaseExpiresAt, self::LEASE_MARGIN_SECONDS)),
            $caller,
        );
    }
}
