<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\OrderSyncRowStatus;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;

/**
 * A stored row as the row-state rules read it; `leaseLive` is by the database clock.
 */
final readonly class OrderSyncStateDTO
{
    public function __construct(
        public OrderSyncRowStatus $status,
        public bool $leaseLive,
        public ?DocType $docType,
        public ?string $qboDocNumber,
        public ?string $qboTxnId,
        public ?SyncReasonCode $lastErrorCode,
    ) {}
}
