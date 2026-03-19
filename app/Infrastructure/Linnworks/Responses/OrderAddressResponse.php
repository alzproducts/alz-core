<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Nested DTO for address fields in Linnworks orders.
 *
 * Used for both shipping (CustomerInfo.Address) and billing
 * (CustomerInfo.BillingAddress) addresses.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class OrderAddressResponse extends Data
{
    public function __construct(
        public readonly string $fullName,
        public readonly string $company,
        public readonly string $address1,
        public readonly string $address2,
        public readonly string $address3,
        public readonly string $town,
        public readonly string $postCode,
        public readonly string $country,
        public readonly ?string $emailAddress = null,
    ) {}
}
