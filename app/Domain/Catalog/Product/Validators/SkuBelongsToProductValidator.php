<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Validators;

use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Validation\Contracts\ValidatorInterface;

/**
 * Validates that all required SKUs belong to a given product.
 *
 * Checks the product's master SKU and all variation SKUs (via Product::allSkus())
 * against the list of required SKUs. Any SKU not found on the product is reported
 * as missing in the result.
 */
final class SkuBelongsToProductValidator implements ValidatorInterface
{
    /**
     * @param  list<Sku>  $requiredSkus
     */
    public function __construct(
        private readonly Product $product,
        private readonly array $requiredSkus,
    ) {}

    public function validate(): SkuBelongsToProductResult
    {
        $productSkuValues = \array_map(
            static fn(Sku $sku): string => $sku->value,
            $this->product->allSkus(),
        );
        $lookup = \array_flip($productSkuValues);

        $missingSkus = \array_values(
            \array_filter(
                $this->requiredSkus,
                static fn(Sku $sku): bool => ! isset($lookup[$sku->value]),
            ),
        );

        return new SkuBelongsToProductResult($missingSkus);
    }
}
