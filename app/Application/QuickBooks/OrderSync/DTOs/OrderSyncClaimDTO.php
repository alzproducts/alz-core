<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use DateTimeImmutable;

final readonly class OrderSyncClaimDTO
{
    public function __construct(
        public string $orderSyncId,
        public string $claimToken,
        public int $attempts,
        public DateTimeImmutable $leaseExpiresAt,
        public ?SyncReasonCode $storedCode,
    ) {}
}
