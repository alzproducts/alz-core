<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

/**
 * Computed meta flags for the product detail API response.
 *
 * Self-constructs all flags from the raw inputs passed to its constructor.
 * Each flag has a dedicated `resolve*()` method to keep logic isolated.
 */
final readonly class ProductViewMeta
{
    public bool $canEditCostPrice;

    /**
     * @param list<ProductVariationView>|null $variations Variations (null = not loaded)
     * @param ProductSupplier|null $defaultSupplier Product-level default supplier
     * @param bool|null $isComposite Whether the parent product is a composite item
     */
    public function __construct(?array $variations, ?ProductSupplier $defaultSupplier, ?bool $isComposite = null)
    {
        $this->canEditCostPrice = self::resolveCanEditCostPrice($variations, $defaultSupplier, $isComposite);
    }

    /**
     * @return array{can_edit_cost_price: bool}
     */
    public function toArray(): array
    {
        return [
            'can_edit_cost_price' => $this->canEditCostPrice,
        ];
    }

    /**
     * Cost price can be edited when a single consistent supplier exists and the product is not composite.
     *
     * Composite products cannot have their cost price edited — their cost is derived
     * from constituent items. This early-return takes precedence over supplier checks.
     *
     * Without variations: requires the product-level default supplier.
     * With variations: requires every non-composite variation to have a default supplier with the same name.
     *
     * @param list<ProductVariationView>|null $variations
     */
    private static function resolveCanEditCostPrice(
        ?array $variations,
        ?ProductSupplier $defaultSupplier,
        ?bool $isComposite,
    ): bool {
        if ($isComposite === true) {
            return false;
        }

        if ($variations === null || $variations === []) {
            return $defaultSupplier !== null;
        }

        return ProductVariationView::commonDefaultSupplier($variations) !== null;
    }
}
