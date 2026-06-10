<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Resolvers;

use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldValueList;
use App\Domain\Catalog\Product\Resolvers\VariationPriceResolver;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VariationPriceResolver domain service.
 *
 * Tests the price resolution logic including ShopWired's sentinel values:
 * - null price = inherit from parent
 * - 0.00 price = explicit zero (valid for selling price, invalid for cost)
 * - -1.0 costPrice = ShopWired sentinel for "inherit from parent"
 */
#[CoversClass(VariationPriceResolver::class)]
final class VariationPriceResolverTest extends TestCase
{
    private VariationPriceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new VariationPriceResolver();
    }

    /*
    |--------------------------------------------------------------------------
    | Price Resolution Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_variation_price_when_set(): void
    {
        $variation = self::createVariation(price: 19.99);
        $result = $this->resolver->resolve($variation, parentPrice: 29.99, parentCostPrice: null, parentSalePrice: null);

        self::assertSame(19.99, $result->price);
    }

    #[Test]
    public function it_inherits_parent_price_when_variation_price_is_null(): void
    {
        $variation = self::createVariation(price: null);
        $result = $this->resolver->resolve($variation, parentPrice: 29.99, parentCostPrice: null, parentSalePrice: null);

        self::assertSame(29.99, $result->price);
    }

    #[Test]
    public function it_keeps_zero_price_when_explicitly_set(): void
    {
        // 0.00 is a valid selling price (e.g., "temporarily removed from sale")
        $variation = self::createVariation(price: 0.0);
        $result = $this->resolver->resolve($variation, parentPrice: 29.99, parentCostPrice: null, parentSalePrice: null);

        self::assertSame(0.0, $result->price);
    }

    /*
    |--------------------------------------------------------------------------
    | Cost Price Resolution Tests (ShopWired Sentinel Values)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_variation_cost_price_when_positive(): void
    {
        $variation = self::createVariation(costPrice: 12.50);
        $result = $this->resolver->resolve($variation, parentPrice: 29.99, parentCostPrice: 15.00, parentSalePrice: null);

        self::assertSame(12.50, $result->costPrice);
    }

    #[Test]
    public function it_inherits_parent_cost_price_when_variation_is_null(): void
    {
        $variation = self::createVariation(costPrice: null);
        $result = $this->resolver->resolve($variation, parentPrice: 29.99, parentCostPrice: 15.00, parentSalePrice: null);

        self::assertSame(15.00, $result->costPrice);
    }

    #[Test]
    public function it_inherits_parent_cost_price_when_variation_is_negative_one_sentinel(): void
    {
        // -1.0 is ShopWired's sentinel for "not set, inherit from parent"
        $variation = self::createVariation(costPrice: -1.0);
        $result = $this->resolver->resolve($variation, parentPrice: 29.99, parentCostPrice: 15.00, parentSalePrice: null);

        self::assertSame(15.00, $result->costPrice);
    }

    #[Test]
    public function it_returns_null_cost_price_when_variation_is_zero(): void
    {
        // 0.00 cost price is invalid (items always have some cost)
        $variation = self::createVariation(costPrice: 0.0);
        $result = $this->resolver->resolve($variation, parentPrice: 29.99, parentCostPrice: 15.00, parentSalePrice: null);

        self::assertNull($result->costPrice);
    }

    #[Test]
    public function it_returns_null_cost_price_when_both_variation_and_parent_are_null(): void
    {
        $variation = self::createVariation(costPrice: null);
        $result = $this->resolver->resolve($variation, parentPrice: 29.99, parentCostPrice: null, parentSalePrice: null);

        self::assertNull($result->costPrice);
    }

    #[Test]
    public function it_returns_null_cost_price_when_sentinel_and_parent_is_null(): void
    {
        $variation = self::createVariation(costPrice: -1.0);
        $result = $this->resolver->resolve($variation, parentPrice: 29.99, parentCostPrice: null, parentSalePrice: null);

        self::assertNull($result->costPrice);
    }

    #[Test]
    public function it_returns_null_cost_price_when_parent_is_negative_one_sentinel(): void
    {
        // Parent's cost price of -1.0 is ShopWired's "not set" sentinel — must be normalized
        // before inheritance, otherwise the sentinel leaks into ResolvedVariationPrices and
        // trips its `greaterThan($costPrice, 0)` assertion.
        $variation = self::createVariation(costPrice: null);
        $result = $this->resolver->resolve($variation, parentPrice: 29.99, parentCostPrice: -1.0, parentSalePrice: null);

        self::assertNull($result->costPrice);
    }

    #[Test]
    public function it_returns_null_cost_price_when_parent_is_zero(): void
    {
        // 0.00 is never a valid cost price; normalize parent to null before inheritance.
        $variation = self::createVariation(costPrice: null);
        $result = $this->resolver->resolve($variation, parentPrice: 29.99, parentCostPrice: 0.0, parentSalePrice: null);

        self::assertNull($result->costPrice);
    }

    /*
    |--------------------------------------------------------------------------
    | Sale Price Resolution Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_variation_sale_price_when_set(): void
    {
        $variation = self::createVariation(salePrice: 14.99);
        $result = $this->resolver->resolve($variation, parentPrice: 29.99, parentCostPrice: null, parentSalePrice: 19.99);

        self::assertSame(14.99, $result->salePrice);
    }

    #[Test]
    public function it_inherits_parent_sale_price_when_variation_is_null(): void
    {
        $variation = self::createVariation(salePrice: null);
        $result = $this->resolver->resolve($variation, parentPrice: 29.99, parentCostPrice: null, parentSalePrice: 19.99);

        self::assertSame(19.99, $result->salePrice);
    }

    #[Test]
    public function it_returns_null_sale_price_when_both_are_null(): void
    {
        $variation = self::createVariation(salePrice: null);
        $result = $this->resolver->resolve($variation, parentPrice: 29.99, parentCostPrice: null, parentSalePrice: null);

        self::assertNull($result->salePrice);
    }

    #[Test]
    public function it_keeps_zero_sale_price_when_explicitly_set(): void
    {
        // 0.00 sale price is valid (item marked as free temporarily)
        $variation = self::createVariation(salePrice: 0.0);
        $result = $this->resolver->resolve($variation, parentPrice: 29.99, parentCostPrice: null, parentSalePrice: 19.99);

        self::assertSame(0.0, $result->salePrice);
    }

    /*
    |--------------------------------------------------------------------------
    | resolveFromProduct() Convenience Method
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function resolve_from_product_extracts_parent_prices(): void
    {
        $variation = self::createVariation(price: null, costPrice: null, salePrice: null);
        $product = self::createProduct(price: 49.99, costPrice: 25.00, salePrice: 39.99);

        $result = $this->resolver->resolveFromProduct($variation, $product);

        self::assertSame(49.99, $result->price);
        self::assertSame(25.00, $result->costPrice);
        self::assertSame(39.99, $result->salePrice);
    }

    /*
    |--------------------------------------------------------------------------
    | Data Providers & Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{variationCost: float|null, parentCost: float|null, expected: float|null}>
     */
    public static function costPriceResolutionProvider(): array
    {
        return [
            'positive variation cost' => ['variationCost' => 10.00, 'parentCost' => 15.00, 'expected' => 10.00],
            'null inherits parent' => ['variationCost' => null, 'parentCost' => 15.00, 'expected' => 15.00],
            '-1 sentinel inherits parent' => ['variationCost' => -1.0, 'parentCost' => 15.00, 'expected' => 15.00],
            'zero becomes null' => ['variationCost' => 0.0, 'parentCost' => 15.00, 'expected' => null],
            'null with null parent' => ['variationCost' => null, 'parentCost' => null, 'expected' => null],
            'parent -1 sentinel normalises to null' => ['variationCost' => null, 'parentCost' => -1.0, 'expected' => null],
            'parent zero normalises to null' => ['variationCost' => null, 'parentCost' => 0.0, 'expected' => null],
            'variation -1 with parent -1 both null' => ['variationCost' => -1.0, 'parentCost' => -1.0, 'expected' => null],
        ];
    }

    #[Test]
    #[DataProvider('costPriceResolutionProvider')]
    public function it_resolves_cost_price_correctly(
        ?float $variationCost,
        ?float $parentCost,
        ?float $expected,
    ): void {
        $variation = self::createVariation(costPrice: $variationCost);
        $result = $this->resolver->resolve($variation, parentPrice: 29.99, parentCostPrice: $parentCost, parentSalePrice: null);

        self::assertSame($expected, $result->costPrice);
    }

    private static function createVariation(
        ?float $price = 29.99,
        ?float $costPrice = 15.00,
        ?float $salePrice = null,
    ): ProductVariation {
        return new ProductVariation(
            id: 1001,
            productExternalId: 12345,
            sku: 'VAR-001',
            price: $price,
            costPrice: $costPrice,
            salePrice: $salePrice,
            stock: 10,
            weight: null,
            gtin: null,
            mpn: null,
            imageIndex: null,
            options: [],
        );
    }

    private static function createProduct(
        float $price = 29.99,
        ?float $costPrice = 15.00,
        ?float $salePrice = null,
    ): Product {
        return new Product(
            id: 12345,
            sku: 'PROD-001',
            gtin: null,
            title: 'Test Product',
            description: null,
            slug: 'test-product',
            url: 'https://example.com/test',
            price: $price,
            costPrice: $costPrice,
            salePrice: $salePrice,
            comparePrice: null,
            stock: 100,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            weight: null,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [],
            variations: [],
            images: [],
            rawCustomFields: [],
            customFields: CustomFieldValueList::empty(),
            rawFilters: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }
}
