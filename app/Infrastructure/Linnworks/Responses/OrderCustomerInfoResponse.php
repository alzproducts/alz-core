<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Nested DTO for the CustomerInfo section of a Linnworks order.
 *
 * Contains the channel buyer name and two nested address objects
 * (shipping and billing).
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class OrderCustomerInfoResponse extends Data
{
    public function __construct(
        public readonly string $channelBuyerName,
        public readonly OrderAddressResponse $address,
        public readonly ?OrderAddressResponse $billingAddress = null,
    ) {}
}
