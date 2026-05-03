<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductViewMeta;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductViewMeta::class)]
final class ProductViewMetaTest extends TestCase
{
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
    // canEditCostPrice — composite awareness
    // ========================================================================

    #[Test]
    public function can_edit_cost_price_false_when_product_itself_is_composite(): void
    {
        $meta = new ProductViewMeta(null, self::createSupplier('Acme'), isComposite: true);

        self::assertFalse($meta->canEditCostPrice);
    }

    #[Test]
    public function can_edit_cost_price_true_when_composite_variations_excluded_and_remaining_share_supplier(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00, supplierName: 'Acme'),
            self::createVariation(price: 25.00, supplierName: 'Acme', isComposite: true),
        ], null);

        self::assertTrue($meta->canEditCostPrice);
    }

    #[Test]
    public function can_edit_cost_price_false_when_all_variations_are_composite(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00, supplierName: 'Acme', isComposite: true),
            self::createVariation(price: 25.00, supplierName: 'Acme', isComposite: true),
        ], null);

        self::assertFalse($meta->canEditCostPrice);
    }

    #[Test]
    public function can_edit_cost_price_composite_with_different_supplier_is_ignored(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00, supplierName: 'Acme'),
            self::createVariation(price: 25.00, supplierName: 'Globex', isComposite: true),
        ], null);

        self::assertTrue($meta->canEditCostPrice);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private static function createVariation(
        float $price,
        ?float $salePrice = null,
        ?string $supplierName = null,
        bool $isComposite = false,
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
            availableStock: 10,
            physicalStock: 10,
            weight: null,
            vatExclusive: false,
            mpn: null,
            imageIndex: null,
            options: [],
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
            defaultSupplier: $supplierName !== null ? self::createSupplier($supplierName) : null,
            isComposite: $isComposite,
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
