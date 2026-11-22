<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

/**
 * The customer a document posts against: its DisplayName finds an existing customer, and the rest creates one on a miss.
 */
final readonly class CustomerDraftDTO
{
    public function __construct(
        public string $displayName,
        public string $givenName,
        public string $familyName,
        public string $companyName,
        public string $email,
        public string $phone,
        public StructuredAddressDTO $billingAddress,
    ) {}
}
