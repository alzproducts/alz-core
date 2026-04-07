<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Validators;

use App\Domain\Shared\Validation\Concerns\ThrowsOnValidationFailureTrait;
use App\Domain\Shared\Validation\Contracts\DescribableValidationResultInterface;

/**
 * Result of price-changed validation.
 */
final readonly class PriceChangedResult implements DescribableValidationResultInterface
{
    use ThrowsOnValidationFailureTrait;

    public function __construct(
        private bool $changed,
        private float $currentBaseGross,
        private float $currentSaleGross,
        private float $currentRrpGross,
    ) {}

    public function passed(): bool
    {
        return $this->changed;
    }

    public function failed(): bool
    {
        return ! $this->changed;
    }

    public function reason(): string
    {
        if ($this->passed()) {
            return '';
        }

        return \sprintf(
            'Prices unchanged: base £%s, sale £%s, rrp £%s',
            \number_format($this->currentBaseGross, 2),
            \number_format($this->currentSaleGross, 2),
            \number_format($this->currentRrpGross, 2),
        );
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        if ($this->passed()) {
            return [];
        }

        return [
            'base_gross' => $this->currentBaseGross,
            'sale_gross' => $this->currentSaleGross,
            'rrp_gross' => $this->currentRrpGross,
        ];
    }
}
