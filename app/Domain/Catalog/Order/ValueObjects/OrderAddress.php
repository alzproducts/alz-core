<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

/**
 * Order address value object (billing or shipping).
 *
 * Contains customer contact and location information.
 */
final readonly class OrderAddress
{
    public function __construct(
        public string $name,
        public string $emailAddress,
        public ?string $telephone,
        public ?string $companyName,
        public string $addressLine1,
        public ?string $addressLine2,
        public ?string $addressLine3,
        public string $city,
        public ?string $province,
        public ?string $state,
        public string $postcode,
        public string $country,
        public int $countryId,
    ) {}
}
