<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;

/**
 * An Invoice with free-form addresses, which keep an empty line for each empty field. The PO number posts to the P.O. Number custom field, never the DocNumber.
 */
final readonly class InvoiceDraftDTO
{
    public function __construct(
        public DocumentCoreDTO $core,
        public string $poNumberCustomField,
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

    /**
     * The default-terms key when the found customer has no sales term of its own, else null (the customer's term applies).
     */
    public function defaultTermsKeyFor(?string $customerSalesTermRef): ?FixedRefKey
    {
        return $customerSalesTermRef === null || $customerSalesTermRef === '' ? FixedRefKey::DefaultTerms : null;
    }
}
