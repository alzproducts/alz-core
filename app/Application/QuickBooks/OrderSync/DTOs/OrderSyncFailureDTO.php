<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;

/**
 * One failed attempt: `code` goes on the row, `loggedCode` on the error row. `message` can hold
 * customer data, so it must stay out of logs and exception context.
 */
final readonly class OrderSyncFailureDTO
{
    public function __construct(
        public SyncReasonCode $code,
        public SyncReasonCode $loggedCode,
        public string $message,
        public ?DocType $docType,
        public ?QuickBooksFault $fault,
        public OrderSyncTrigger $trigger,
        public ?string $runId,
    ) {}
}
