<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\OrderSyncRowStatus;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use DateTimeImmutable;

final readonly class FailedOrderSyncDTO
{
    /**
     * @param DateTimeImmutable|null $placedAt null once the order no longer exists
     */
    public function __construct(
        public int $reference,
        public int $orderExternalId,
        public ?DateTimeImmutable $placedAt,
        public ?DocType $docType,
        public OrderSyncRowStatus $status,
        public int $attempts,
        public DateTimeImmutable $lastAttemptedAt,
        public SyncReasonCode $reason,
        public string $message,
    ) {}
}
