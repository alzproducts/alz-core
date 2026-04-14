<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductViewMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductViewMeta::class)]
final class ProductViewMetaTest extends TestCase
{
    // ========================================================================
    // canEditRrp
    // ========================================================================

    #[Test]
    public function can_edit_rrp_true_when_variations_null(): void
    {
        $meta = new ProductViewMeta(null, null);

        self::assertTrue($meta->canEditRrp);
    }

    #[Test]
    public function can_edit_rrp_true_when_variations_empty(): void
    {
        $meta = new ProductViewMeta([], null);

        self::assertTrue($meta->canEditRrp);
    }

    #[Test]
    public function can_edit_rrp_true_when_single_variation(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00),
        ], null);

        self::assertTrue($meta->canEditRrp);
    }

    #[Test]
    public function can_edit_rrp_true_when_all_variations_have_same_price(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00),
            self::createVariation(price: 20.00),
            self::createVariation(price: 20.00),
        ], null);

        self::assertTrue($meta->canEditRrp);
    }

    #[Test]
    public function can_edit_rrp_false_when_variations_have_different_prices(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00),
            self::createVariation(price: 25.00),
        ], null);

        self::assertFalse($meta->canEditRrp);
    }

    #[Test]
    public function can_edit_rrp_false_when_one_variation_differs(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00),
            self::createVariation(price: 20.00),
            self::createVariation(price: 21.00),
        ], null);

        self::assertFalse($meta->canEditRrp);
    }

    #[Test]
    public function can_edit_rrp_true_regardless_of_sale_prices(): void
    {
        // Same base price, different sale prices — RRP is tied to base price, not effective
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00, salePrice: 15.00),
            self::createVariation(price: 20.00, salePrice: 12.00),
        ], null);

        self::assertTrue($meta->canEditRrp);
    }

    // ========================================================================
    // canEditCostPrice
    // ========================================================================

    #[Test]
    public function can_edit_cost_price_false_when_no_variations_and_no_default_supplier(): void
    {
        $meta = new ProductViewMeta(null, null);

        self::assertFalse($meta->canEditCostPrice);
    }

    #[Test]
    public function can_edit_cost_price_false_when_empty_variations_and_no_default_supplier(): void
    {
        $meta = new ProductViewMeta([], null);

        self::assertFalse($meta->canEditCostPrice);
    }

    #[Test]
    public function can_edit_cost_price_true_when_no_variations_and_has_default_supplier(): void
    {
        $meta = new ProductViewMeta(null, self::createSupplier('Acme'));

        self::assertTrue($meta->canEditCostPrice);
    }

    #[Test]
    public function can_edit_cost_price_true_when_empty_variations_and_has_default_supplier(): void
    {
        $meta = new ProductViewMeta([], self::createSupplier('Acme'));

        self::assertTrue($meta->canEditCostPrice);
    }

    #[Test]
    public function can_edit_cost_price_true_when_all_variations_have_same_default_supplier(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00, supplierName: 'Acme'),
            self::createVariation(price: 25.00, supplierName: 'Acme'),
        ], null);

        self::assertTrue($meta->canEditCostPrice);
    }

    #[Test]
    public function can_edit_cost_price_false_when_variations_have_different_default_suppliers(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00, supplierName: 'Acme'),
            self::createVariation(price: 20.00, supplierName: 'Globex'),
        ], null);

        self::assertFalse($meta->canEditCostPrice);
    }

    #[Test]
    public function can_edit_cost_price_false_when_any_variation_has_no_default_supplier(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00, supplierName: 'Acme'),
            self::createVariation(price: 20.00),
        ], null);

        self::assertFalse($meta->canEditCostPrice);
    }

    #[Test]
    public function can_edit_cost_price_false_when_all_variations_have_no_default_supplier(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00),
            self::createVariation(price: 25.00),
        ], null);

        self::assertFalse($meta->canEditCostPrice);
    }

    #[Test]
    public function can_edit_cost_price_true_when_single_variation_has_default_supplier(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00, supplierName: 'Acme'),
        ], null);

        self::assertTrue($meta->canEditCostPrice);
    }

    #[Test]
    public function can_edit_cost_price_false_when_single_variation_has_no_default_supplier(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00),
        ], null);

        self::assertFalse($meta->canEditCostPrice);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private static function createVariation(
        float $price,
        ?float $salePrice = null,
        ?string $supplierName = null,
    ): ProductVariationView {
        return new ProductVariationView(
            externalId: 1,
            sku: null,
            gtin: null,
            price: $price,
            costPrice: null,
            salePrice: $salePrice,
            rrp: null,
            effectivePrice: $salePrice !== null && $salePrice > 0 ? $salePrice : $price,
            isOnSale: $salePrice !== null && $salePrice > 0,
            profitMargin: null,
            stock: 10,
            weight: null,
            vatExclusive: false,
            mpn: null,
            imageIndex: null,
            options: [],
            defaultSupplier: $supplierName !== null ? self::createSupplier($supplierName) : null,
        );
    }

    private static function createSupplier(string $name): ProductSupplier
    {
        return new ProductSupplier(
            supplierName: $name,
            purchasePrice: null,
            isDefault: true,
        );
    }
}
