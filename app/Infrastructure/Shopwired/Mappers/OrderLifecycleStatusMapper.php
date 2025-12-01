<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Mappers;

use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;

/**
 * Maps domain OrderLifecycleStatus to ShopWired status IDs.
 *
 * Status IDs are account-specific in ShopWired. These values are for
 * the production account and should be verified if account changes.
 *
 * @see .ai/docs/api-reference/shopwired-order-statuses.json for raw API response
 */
final class OrderLifecycleStatusMapper
{
    /**
     * ShopWired status ID mapping.
     *
     * Maps OrderLifecycleStatus enum cases to their corresponding
     * ShopWired status IDs for this account.
     *
     * @var array<string, int>
     */
    private const array STATUS_IDS = [
        'Processing' => 178012,
        'Dispatched' => 73879,
        'PartDispatched' => 73879,  // ShopWired has no part-dispatched, use Dispatched
        'PartRefunded' => 73881,
        'Refunded' => 73882,
        'Cancelled' => 73878,
    ];

    /**
     * Convert a domain lifecycle status to ShopWired status ID.
     */
    public static function toShopwiredId(OrderLifecycleStatus $status): int
    {
        return self::STATUS_IDS[$status->name];
    }
}
