<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Address (billing/shipping).
 *
 * Infrastructure DTO for parsing address data from order responses.
 * Used for both billingAddress and shippingAddress fields.
 *
 * Note: Address has BOTH `state` and `province` as separate fields.
 * Numeric suffixes require explicit MapInputName (SnakeCaseMapper doesn't handle them).
 *
 * Domain conversion will be added after smoke testing validates parsing.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderAddress extends Data
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $emailAddress = null,
        public readonly ?string $telephone = null,
        public readonly ?string $companyName = null,
        #[MapInputName('address_line_1')]
        public readonly ?string $addressLine1 = null,
        #[MapInputName('address_line_2')]
        public readonly ?string $addressLine2 = null,
        #[MapInputName('address_line_3')]
        public readonly ?string $addressLine3 = null,
        public readonly ?string $city = null,
        public readonly ?string $province = null,
        public readonly ?string $state = null,
        public readonly ?string $postcode = null,
        public readonly ?string $country = null,
        public readonly ?int $countryId = null,
    ) {}
}
