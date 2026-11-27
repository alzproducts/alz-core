<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\OrderSyncSettingsInterface;
use App\Application\QuickBooks\OrderSync\DTOs\ClaimedOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderSyncCalendarResolver;
use App\Application\QuickBooks\OrderSync\Resolvers\SyncOutcomeResolver;
use App\Application\QuickBooks\OrderSync\Results\OrderFloorResult;
use App\Domain\Exceptions\InvalidConfigurationException;
use DateTimeImmutable;

/**
 * Refuses a claimed order placed before the backfill floor date; a request checks only the orders that exist then.
 */
final readonly class OrderSyncFloorService
{
    public function __construct(
        private OrderSyncSettingsInterface $settings,
    ) {}

    /**
     * @throws InvalidConfigurationException when QUICKBOOKS_BACKFILL_FLOOR_DATE is unset or malformed
     */
    public function floorStart(): DateTimeImmutable
    {
        $floor = $this->settings->backfillFloor();
        if ($floor === null) {
            throw new InvalidConfigurationException('QUICKBOOKS_BACKFILL_FLOOR_DATE', 'The QuickBooks backfill floor date is not set');
        }

        return OrderSyncCalendarResolver::startOfDate($floor);
    }

    /**
     * @param list<ClaimedOrderDTO> $claimed
     *
     * @throws InvalidConfigurationException when QUICKBOOKS_BACKFILL_FLOOR_DATE is unset or malformed
     */
    public function withinFloor(array $claimed): OrderFloorResult
    {
        $floorStart = $this->floorStart();

        return OrderFloorResult::of(\array_map(
            static fn(ClaimedOrderDTO $order): ClaimedOrderDTO|OrderSyncOutcomeDTO => $order->row->createdAt < $floorStart
                ? OrderSyncOutcomeDTO::failed($order, SyncOutcomeResolver::forFloor($order->claim->storedCode), null)
                : $order,
            $claimed,
        ));
    }
}
