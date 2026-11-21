<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;

final readonly class OrderSyncErrorDTO
{
    public function __construct(
        public OrderSyncTrigger $trigger,
        public ?string $runId,
        public string $reasonCode,
        public ?string $qboFaultCode = null,
        public ?string $qboFaultElement = null,
        public ?string $message = null,
    ) {}
}
