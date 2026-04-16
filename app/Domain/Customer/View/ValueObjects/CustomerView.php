<?php

declare(strict_types=1);

namespace App\Domain\Customer\View\ValueObjects;

use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;

/**
 * Read-only API projection of a customer.
 *
 * Distinct from the write-side `Customer` VO — the View omits address/phone/
 * notes/customFields (not needed by list/show endpoints).
 */
final readonly class CustomerView
{
    public IntId $id;

    /**
     * @param int $externalId ShopWired customer ID
     * @param string $email Customer email
     * @param string $firstName Customer first name
     * @param string $lastName Customer last name
     * @param bool $isTrade Whether this is a trade customer
     * @param bool $isActive Whether the customer account is active
     * @param DateTimeImmutable $createdAt When the customer was created in ShopWired
     */
    public function __construct(
        int $externalId,
        public string $email,
        public string $firstName,
        public string $lastName,
        public bool $isTrade,
        public bool $isActive,
        public DateTimeImmutable $createdAt,
    ) {
        $this->id = IntId::from($externalId);
    }

    /**
     * Get customer's full name (trimmed).
     */
    public function fullName(): string
    {
        return \mb_trim($this->firstName . ' ' . $this->lastName);
    }
}
