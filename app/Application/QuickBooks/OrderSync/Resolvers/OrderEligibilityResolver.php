<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Resolvers;

use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncStateDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncRowStatus;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Application\QuickBooks\OrderSync\Results\OrderEligibilityResult;
use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;

/**
 * The row-state rules, first match wins. A posted order is checked first so it never reads as skipped; a cancelled
 * or missing order is checked before a stored failure so it reports its current state over a stale one.
 */
final class OrderEligibilityResolver
{
    /**
     * @param OrderSyncStateDTO|null    $state       null when the reference has no row
     * @param OrderLifecycleStatus|null $orderStatus null when no order has the reference
     */
    public static function resolve(?OrderSyncStateDTO $state, ?OrderLifecycleStatus $orderStatus, bool $forceRetry): OrderEligibilityResult
    {
        return match (true) {
            $state?->status === OrderSyncRowStatus::Synced => OrderEligibilityResult::alreadySynced(),
            $orderStatus === OrderLifecycleStatus::Cancelled => OrderEligibilityResult::refused(SyncReasonCode::Cancelled),
            $orderStatus === null => OrderEligibilityResult::refused(SyncReasonCode::OrderNotFound),
            $state?->status === OrderSyncRowStatus::Failed && !$forceRetry => OrderEligibilityResult::refused(SyncReasonCode::PreviouslyFailed),
            $state?->status === OrderSyncRowStatus::Processing && $state->leaseLive => OrderEligibilityResult::refused(SyncReasonCode::InProgress),
            default => OrderEligibilityResult::claimable(),
        };
    }
}
