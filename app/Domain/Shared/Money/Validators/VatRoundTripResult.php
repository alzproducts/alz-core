<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money\Validators;

use App\Domain\Shared\Validation\Concerns\ThrowsOnValidationFailureTrait;
use App\Domain\Shared\Validation\Contracts\DescribableValidationResultInterface;

/**
 * Result of a single VAT round-trip validation check.
 */
final readonly class VatRoundTripResult implements DescribableValidationResultInterface
{
    use ThrowsOnValidationFailureTrait;

    public function __construct(
        private bool $valid,
        private string $sku,
        private string $field,
        private float $amount,
    ) {}

    public function passed(): bool
    {
        return $this->valid;
    }

    public function failed(): bool
    {
        return ! $this->valid;
    }

    public function reason(): string
    {
        if ($this->passed()) {
            return '';
        }

        return \sprintf(
            'Price %s on %s (%s) does not survive VAT round trip',
            \number_format($this->amount, 2),
            $this->sku,
            $this->field,
        );
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        if ($this->passed()) {
            return [];
        }

        return [
            'sku' => $this->sku,
            'field' => $this->field,
            'amount' => $this->amount,
        ];
    }
}
