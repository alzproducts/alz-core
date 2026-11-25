<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

/**
 * A source order as stored, and the lease this chunk holds on its row.
 */
final readonly class ClaimedOrderDTO
{
    public function __construct(
        public SourceOrderRowDTO $row,
        public OrderSyncClaimDTO $claim,
    ) {}
}
