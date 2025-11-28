<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Address (billing/shipping).
 *
 * Always embedded in Standard/Detail modes.
 * Core address fields are non-nullable; optional contact fields are nullable.
 *
 * Note: Address has BOTH `state` and `province` as separate fields.
 * Numeric suffixes require explicit MapInputName (SnakeCaseMapper doesn't handle them).
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderAddress extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $emailAddress,
        public readonly ?string $telephone,
        public readonly ?string $companyName,
        #[MapInputName('address_line_1')]
        public readonly string $addressLine1,
        #[MapInputName('address_line_2')]
        public readonly ?string $addressLine2,
        #[MapInputName('address_line_3')]
        public readonly ?string $addressLine3,
        public readonly string $city,
        public readonly ?string $province,
        public readonly ?string $state,
        public readonly string $postcode,
        public readonly string $country,
        public readonly int $countryId,
    ) {}

    public function toDomain(): \App\Domain\Catalog\Order\ValueObjects\OrderAddress
    {
        return new \App\Domain\Catalog\Order\ValueObjects\OrderAddress(
            name: $this->name,
            emailAddress: $this->emailAddress,
            telephone: $this->telephone,
            companyName: $this->companyName,
            addressLine1: $this->addressLine1,
            addressLine2: $this->addressLine2,
            addressLine3: $this->addressLine3,
            city: $this->city,
            province: $this->province,
            state: $this->state,
            postcode: $this->postcode,
            country: $this->country,
        );
    }
}
