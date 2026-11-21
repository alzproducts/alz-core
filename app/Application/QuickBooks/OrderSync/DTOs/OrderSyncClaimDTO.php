<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

final readonly class OrderSyncClaimDTO
{
    public function __construct(
        public string $orderSyncId,
        public string $claimToken,
        public int $attempts,
    ) {}
}
