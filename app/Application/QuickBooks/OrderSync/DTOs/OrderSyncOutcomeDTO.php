<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\SyncOutcome;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;

/**
 * A claimed order's outcome before it is recorded: a document QBO holds, a failure, or a deadline refusal.
 */
final readonly class OrderSyncOutcomeDTO
{
    private function __construct(
        public ClaimedOrderDTO $order,
        public OrderSyncPostedDTO|SyncFailureDTO $detail,
        public SyncOutcome $outcome,
        public ?DocType $docType,
    ) {}

    public static function created(ReadyOrderDTO $order, string $qboTxnId): self
    {
        $draft = $order->document->draft;
        $posted = new OrderSyncPostedDTO($draft->docType(), $qboTxnId, $draft->core->docNumber, null);

        return new self($order->order, $posted, SyncOutcome::Synced, $posted->docType);
    }

    public static function found(ClaimedOrderDTO $order, ExistingDocumentDTO $document): self
    {
        $posted = new OrderSyncPostedDTO($document->type, $document->qboId, $document->docNumber, null);

        return new self($order, $posted, SyncOutcome::AlreadySynced, $posted->docType);
    }

    public static function failed(ClaimedOrderDTO $order, SyncFailureDTO $failure, ?DocType $docType): self
    {
        return new self($order, $failure, $failure->code->outcome(), $docType);
    }

    /**
     * Nothing was attempted for the order, so its row is released.
     */
    public static function released(ClaimedOrderDTO $order): self
    {
        $refusal = SyncFailureDTO::of(SyncReasonCode::DeadlineExceeded);

        return new self($order, $refusal, $refusal->code->outcome(), null);
    }
}
