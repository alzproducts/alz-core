<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Validators;

use App\Domain\Shared\Validation\Concerns\ThrowsOnValidationFailureTrait;
use App\Domain\Shared\Validation\Contracts\DescribableValidationResultInterface;

/**
 * Result of retail pricing validation.
 */
final readonly class HasValidRetailPricingResult implements DescribableValidationResultInterface
{
    use ThrowsOnValidationFailureTrait;

    public function __construct(
        private bool $valid,
        private float $baseGross,
        private float $saleGross,
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

        if ($this->baseGross <= 0.0) {
            return \sprintf(
                'basePrice (£%s) must be greater than zero',
                \number_format($this->baseGross, 2),
            );
        }

        return \sprintf(
            'salePrice (£%s) must be less than basePrice (£%s)',
            \number_format($this->saleGross, 2),
            \number_format($this->baseGross, 2),
        );
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        if ($this->passed()) {
            return [];
        }

        return [
            'base_gross' => $this->baseGross,
            'sale_gross' => $this->saleGross,
        ];
    }
}
