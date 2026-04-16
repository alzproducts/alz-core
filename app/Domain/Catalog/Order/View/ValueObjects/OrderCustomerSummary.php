<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\View\ValueObjects;

/**
 * Slim read-side projection of order billing contact.
 *
 * Distinct from write-side `OrderCustomer` (which carries id/type/dateOfBirth/deviceInfo
 * for sync and webhook paths). The View only needs identification fields suitable
 * for list/show endpoints.
 */
final readonly class OrderCustomerSummary
{
    public function __construct(
        public string $email,
        public string $fullName,
    ) {}
}
