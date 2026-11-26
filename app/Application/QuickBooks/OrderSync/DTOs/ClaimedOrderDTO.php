<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

/**
 * A source order as stored, the lease this chunk holds on its row, and whether this is the chunk's last attempt.
 */
final readonly class ClaimedOrderDTO
{
    public function __construct(
        public SourceOrderRowDTO $row,
        public OrderSyncClaimDTO $claim,
        public bool $lastAttempt,
    ) {}
}
