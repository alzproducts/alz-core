<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\ValueObjects;

use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;

/**
 * An Invoice with free-form addresses, which keep an empty line for each empty field.
 */
final readonly class InvoiceDraft
{
    public function __construct(
        public DocumentCore $core,
        public string $poNumber,
        public string $shipVia,
        public string $billingAddressText,
        public string $shippingAddressText,
    ) {}

    public function docType(): DocType
    {
        return DocType::Invoice;
    }

    /**
     * @return list<FixedRefKey>
     */
    public function fixedRefKeys(): array
    {
        return [FixedRefKey::Department, FixedRefKey::SalesClass];
    }

    public function allowsOnlineCardPayment(): bool
    {
        return false;
    }

    public function allowsOnlineAchPayment(): bool
    {
        return false;
    }

    public function termsFor(?string $customerSalesTermRef): InvoiceTerms
    {
        return InvoiceTerms::forCustomer($customerSalesTermRef);
    }
}
