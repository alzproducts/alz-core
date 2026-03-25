<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money\Validators;

use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\Shared\Validation\Contracts\ValidatorInterface;
use App\Domain\ValueObjects\TaxRate;

/**
 * Validates that a gross price survives the VAT round trip without rounding drift.
 *
 * Short-lived validator: constructed with price data, validated once, discarded.
 */
final readonly class VatRoundTripValidator implements ValidatorInterface
{
    public function __construct(
        private float $grossAmount,
        private string $sku,
        private string $field,
        private TaxRate $taxRate,
    ) {}

    public function validate(): VatRoundTripResult
    {
        return new VatRoundTripResult(
            valid: Money::isVatRoundTripSafe($this->grossAmount, $this->taxRate),
            sku: $this->sku,
            field: $this->field,
            amount: $this->grossAmount,
        );
    }
}
