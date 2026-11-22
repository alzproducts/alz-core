<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

final readonly class OrderSyncPostedDTO
{
    /**
     * @param 'invoice'|'sales_receipt' $docType
     */
    public function __construct(
        public string $docType,
        public string $qboTxnId,
        public ?string $qboDocNumber,
        public ?string $payloadHash,
    ) {}
}
