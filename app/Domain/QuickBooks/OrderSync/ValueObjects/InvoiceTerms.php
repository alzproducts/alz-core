<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\ValueObjects;

use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;

/**
 * An invoice's sales term: the found customer's own term ref, else the default-terms key. Exactly one is set.
 */
final readonly class InvoiceTerms
{
    private function __construct(
        public ?string $customerSalesTermRef,
        public ?FixedRefKey $defaultTermsKey,
    ) {}

    public static function forCustomer(?string $customerSalesTermRef): self
    {
        if ($customerSalesTermRef === null || $customerSalesTermRef === '') {
            return new self(null, FixedRefKey::DefaultTerms);
        }

        return new self($customerSalesTermRef, null);
    }
}
