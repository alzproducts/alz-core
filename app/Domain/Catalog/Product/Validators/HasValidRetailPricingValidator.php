<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Validators;

use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Shared\Validation\Contracts\ValidatorInterface;

/**
 * Validates retail pricing constraints within a ProductRetailPricing.
 */
final readonly class HasValidRetailPricingValidator implements ValidatorInterface
{
    public function __construct(
        private ProductRetailPricing $pricing,
    ) {}

    public function validate(): HasValidRetailPricingResult
    {
        $baseGross = $this->pricing->basePrice->toGross();
        $saleGross = $this->pricing->salePrice?->toGross() ?? 0.0;

        return new HasValidRetailPricingResult(
            valid: $this->isValid($baseGross, $saleGross),
            baseGross: $baseGross,
            saleGross: $saleGross,
        );
    }

    private function isValid(float $baseGross, float $saleGross): bool
    {
        if ($baseGross <= 0.0) {
            return false;
        }

        // No sale or zero sale = valid (only base price matters)
        if ($saleGross === 0.0) {
            return true;
        }

        return $saleGross < $baseGross;
    }
}
