<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Domain\QuickBooks\OrderSync\Enums\DocType;

final readonly class OrderSyncPostedDTO
{
    public function __construct(
        public DocType $docType,
        public string $qboTxnId,
        public ?string $qboDocNumber,
        public ?string $payloadHash,
    ) {}
}
