<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\ValueObjects;

use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;
use App\Domain\QuickBooks\OrderSync\Enums\PaymentKey;

/**
 * A SalesReceipt. A null payment key posts empty deposit and payment refs, never a missing mapping.
 */
final readonly class SalesReceiptDraft
{
    public function __construct(
        public DocumentCore $core,
        public string $paymentRefNum,
        public StructuredAddress $billingAddress,
        public StructuredAddress $shippingAddress,
        public string $transactionId,
        public ?PaymentKey $paymentKey,
    ) {}

    public function docType(): DocType
    {
        return DocType::SalesReceipt;
    }

    /**
     * @return list<FixedRefKey>
     */
    public function fixedRefKeys(): array
    {
        return [FixedRefKey::Department];
    }
}
