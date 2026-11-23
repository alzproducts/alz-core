<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\SyncOutcome;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;

/**
 * One reference's outcome. `message` can hold customer data, so it must stay out of logs.
 */
final readonly class OrderSyncResultDTO
{
    private function __construct(
        public int $reference,
        public SyncOutcome $outcome,
        public ?DocType $docType,
        public ?string $qboDocNumber,
        public ?string $qboTxnId,
        public ?SyncReasonCode $reason,
        public ?string $message,
    ) {}

    public static function alreadySynced(int $reference, ?DocType $docType, ?string $qboDocNumber, ?string $qboTxnId): self
    {
        return new self($reference, SyncOutcome::AlreadySynced, $docType, $qboDocNumber, $qboTxnId, null, null);
    }

    public static function ofOutcome(OrderSyncOutcomeDTO $outcome): self
    {
        $reference = $outcome->order->row->reference;
        $detail = $outcome->detail;

        return $detail instanceof OrderSyncPostedDTO
            ? new self($reference, $outcome->outcome, $detail->docType, $detail->qboDocNumber, $detail->qboTxnId, null, null)
            : new self($reference, $outcome->outcome, $outcome->docType, null, null, $detail->code, $detail->message);
    }

    public static function refused(int $reference, SyncReasonCode $reason): self
    {
        return new self($reference, $reason->outcome(), null, null, null, $reason, $reason->message());
    }
}
