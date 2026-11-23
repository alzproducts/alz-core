<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

use App\Application\QuickBooks\OrderSync\DTOs\ClaimedOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;

/**
 * A chunk's claimed orders, and a result for each reference that was not claimed.
 */
final readonly class OrderClaimResult
{
    /**
     * @param list<ClaimedOrderDTO>    $claimed
     * @param list<OrderSyncResultDTO> $refused
     */
    public function __construct(
        public array $claimed,
        public array $refused,
    ) {}
}
