<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;
use App\Domain\QuickBooks\OrderSync\Enums\PaymentKey;

/**
 * A SalesReceipt. A null payment key posts empty deposit and payment refs, never a missing mapping.
 */
final readonly class SalesReceiptDraftDTO
{
    public function __construct(
        public DocumentCoreDTO $core,
        public string $paymentRefNum,
        public StructuredAddressDTO $billingAddress,
        public StructuredAddressDTO $shippingAddress,
        public string $transactionIdCustomField,
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
