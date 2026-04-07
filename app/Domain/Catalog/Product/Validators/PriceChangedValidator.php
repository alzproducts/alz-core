<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Validators;

use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Shared\Money\Validators\MoneyEqualsValidator;
use App\Domain\Shared\Money\Validators\NullableMoneyEqualsValidator;
use App\Domain\Shared\Validation\Contracts\ValidatorInterface;

/**
 * Checks whether two ProductRetailPricing objects differ.
 */
final readonly class PriceChangedValidator implements ValidatorInterface
{
    public function __construct(
        private ProductRetailPricing $proposed,
        private ProductRetailPricing $current,
    ) {}

    public function validate(): PriceChangedResult
    {
        return new PriceChangedResult(
            changed: $this->detectChange(),
            currentBaseGross: $this->current->basePrice->toGross(),
            currentSaleGross: $this->current->salePrice?->toGross() ?? 0.0,
            currentRrpGross: $this->current->rrp?->toGross() ?? 0.0,
        );
    }

    private function detectChange(): bool
    {
        $basePriceEqual = (new MoneyEqualsValidator(
            proposed: $this->proposed->basePrice,
            current: $this->current->basePrice,
        ))->validate()->passed();

        $salePriceEqual = (new NullableMoneyEqualsValidator(
            proposed: $this->proposed->salePrice,
            current: $this->current->salePrice,
        ))->validate()->passed();

        $rrpEqual = (new NullableMoneyEqualsValidator(
            proposed: $this->proposed->rrp,
            current: $this->current->rrp,
        ))->validate()->passed();

        return ! $basePriceEqual || ! $salePriceEqual || ! $rrpEqual;
    }
}
