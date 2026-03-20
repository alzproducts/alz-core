<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Nested DTO for the TotalsInfo section of a Linnworks order.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class OrderTotalsInfoResponse extends Data
{
    public function __construct(
        public readonly float $totalCharge,
        public readonly float $subtotal,
        public readonly float $tax,
        public readonly string $paymentMethod,
        public readonly float $postageCost,
        public readonly float $postageCostExTax,
        public readonly string $currency,
        public readonly string $paymentMethodId,
    ) {}
}
