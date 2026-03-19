<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Nested DTO for the ShippingInfo section of a Linnworks order.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class OrderShippingInfoResponse extends Data
{
    public function __construct(
        public readonly string $postalServiceName,
        public readonly string $vendor,
        public readonly string $trackingNumber,
    ) {}
}
