<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Inventory\Services;

use App\Application\Inventory\Commands\GenerateVariantSkusCommand;
use App\Application\Inventory\DTOs\VariationProcessingContextDTO;
use App\Application\Inventory\Services\StockItemParamsBuilderService;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldValueList;
use App\Domain\Catalog\Product\Resolvers\VariationImageResolver;
use App\Domain\Catalog\Product\Resolvers\VariationOptionMatcher;
use App\Domain\Catalog\Product\Resolvers\VariationPriceResolver;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Inventory\ValueObjects\Dimensions;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\Inventory\ValueObjects\StockItemSupplier;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\TaxType;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for StockItemParamsBuilderService.
 *
 * Per TestingStrategy.md: Application Service with transformations and branching.
 * Tests pricing priority, flag handling, and title building — not framework glue.
 */
#[CoversClass(StockItemParamsBuilderService::class)]
final class StockItemParamsBuilderServiceTest extends TestCase
{
    private StockItemParamsBuilderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new StockItemParamsBuilderService(
            new VariationPriceResolver(),
            new VariationImageResolver(),
            new VariationOptionMatcher(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Title Building Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_builds_title_with_option_values(): void
    {
        $variation = self::createVariation(options: [
            self::option('Size', 'Large'),
            self::option('Color', 'Red'),
        ]);

        $result = $this->service->build(
            $variation,
            self::createContext(),
        );

        self::assertSame('Test Product - Large Red', $result->title);
    }

    #[Test]
    public function it_builds_title_without_options(): void
    {
        $variation = self::createVariation(options: []);

        $result = $this->service->build(
            $variation,
            self::createContext(),
        );

        self::assertSame('Test Product', $result->title);
    }

    /*
    |--------------------------------------------------------------------------
    | Tax Treatment Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_inclusive_pricing_for_vat_inclusive_products(): void
    {
        $product = self::createProduct(vatExclusive: false);

        $result = $this->service->build(
            self::createVariation(),
            self::createContext(product: $product),
        );

        self::assertSame(TaxType::Inclusive, $result->retailPrice->taxType);
        self::assertSame(29.99, $result->retailPrice->toGross());
    }

    #[Test]
    public function it_uses_zero_rated_pricing_for_vat_exclusive_products(): void
    {
        $product = self::createProduct(vatExclusive: true);

        $result = $this->service->build(
            self::createVariation(),
            self::createContext(product: $product),
        );

        self::assertSame(TaxType::ZeroRated, $result->retailPrice->taxType);
        self::assertSame(29.99, $result->retailPrice->toGross());
    }

    #[Test]
    public function it_uses_zero_tax_rate_for_vat_exclusive_products(): void
    {
        $product = self::createProduct(vatExclusive: true);

        $result = $this->service->build(
            self::createVariation(),
            self::createContext(product: $product),
        );

        self::assertSame(0.0, $result->taxRate->percentage);
    }

    #[Test]
    public function it_uses_standard_tax_rate_for_vat_inclusive_products(): void
    {
        $product = self::createProduct(vatExclusive: false);

        $result = $this->service->build(
            self::createVariation(),
            self::createContext(product: $product),
        );

        self::assertSame(20.0, $result->taxRate->percentage);
    }

    /*
    |--------------------------------------------------------------------------
    | Purchase Price Priority Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_variation_cost_price_when_no_standard_sign(): void
    {
        $variation = self::createVariation(costPrice: 12.50);

        $result = $this->service->build(
            $variation,
            self::createContext(),
        );

        self::assertNotNull($result->purchasePrice);
        self::assertSame(12.50, $result->purchasePrice->toNet());
        self::assertSame(TaxType::Exclusive, $result->purchasePrice->taxType);
    }

    #[Test]
    public function it_returns_null_purchase_price_when_cost_price_unknown(): void
    {
        // costPrice = 0.0 is treated as null by the price resolver
        $variation = self::createVariation(costPrice: 0.0);
        $product = self::createProduct(costPrice: null);

        $result = $this->service->build(
            $variation,
            self::createContext(product: $product),
        );

        self::assertNull($result->purchasePrice);
    }

    #[Test]
    public function it_prioritises_standard_sign_match_over_variation_cost(): void
    {
        $variation = self::createVariation(
            costPrice: 12.50,
            options: [self::option('Size', '300mm'), self::option('Color', 'Blue')],
        );

        // Standard sign reference with matching options but different cost
        $referenceVariation = self::createVariation(
            id: 99,
            costPrice: 8.75,
            options: [self::option('Size', '300mm'), self::option('Color', 'Blue')],
        );

        $result = $this->service->build(
            $variation,
            self::createContext(
                command: self::createCommand(isStandardSign: true),
                standardSignVariations: [$referenceVariation],
            ),
        );

        self::assertNotNull($result->purchasePrice);
        self::assertSame(8.75, $result->purchasePrice->toNet());
    }

    #[Test]
    public function it_falls_back_to_variation_cost_when_standard_sign_has_no_match(): void
    {
        $variation = self::createVariation(
            costPrice: 12.50,
            options: [self::option('Size', '300mm'), self::option('Color', 'Purple')],
        );

        // Standard sign reference with different options — no match
        $referenceVariation = self::createVariation(
            id: 99,
            costPrice: 8.75,
            options: [self::option('Size', '300mm'), self::option('Color', 'Blue')],
        );

        $result = $this->service->build(
            $variation,
            self::createContext(
                command: self::createCommand(isStandardSign: true),
                standardSignVariations: [$referenceVariation],
            ),
        );

        self::assertNotNull($result->purchasePrice);
        self::assertSame(12.50, $result->purchasePrice->toNet());
    }

    #[Test]
    public function it_falls_back_to_variation_cost_when_standard_sign_match_has_null_cost(): void
    {
        $variation = self::createVariation(
            costPrice: 12.50,
            options: [self::option('Color', 'Blue')],
        );

        // Matching options but null cost on the reference
        $referenceVariation = self::createVariation(
            id: 99,
            costPrice: null,
            options: [self::option('Color', 'Blue')],
        );

        $result = $this->service->build(
            $variation,
            self::createContext(
                command: self::createCommand(isStandardSign: true),
                standardSignVariations: [$referenceVariation],
            ),
        );

        self::assertNotNull($result->purchasePrice);
        self::assertSame(12.50, $result->purchasePrice->toNet());
    }

    /*
    |--------------------------------------------------------------------------
    | --copy-mpn Flag Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_variation_mpn_by_default(): void
    {
        $variation = self::createVariation(mpn: 'VAR-MPN-001');

        $result = $this->service->build(
            $variation,
            self::createContext(command: self::createCommand(copyParentMpn: false)),
        );

        self::assertSame('VAR-MPN-001', $result->mpn);
    }

    #[Test]
    public function it_copies_supplier_code_as_mpn_when_copy_parent_mpn_enabled(): void
    {
        $variation = self::createVariation(mpn: 'VAR-MPN-001');

        $result = $this->service->build(
            $variation,
            self::createContext(command: self::createCommand(copyParentMpn: true)),
        );

        self::assertSame('SUP-CODE', $result->mpn);
    }

    #[Test]
    public function it_returns_null_mpn_when_variation_has_none_and_copy_disabled(): void
    {
        $variation = self::createVariation(mpn: null);

        $result = $this->service->build(
            $variation,
            self::createContext(command: self::createCommand(copyParentMpn: false)),
        );

        self::assertNull($result->mpn);
    }

    /*
    |--------------------------------------------------------------------------
    | --no-supplier Flag Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_includes_supplier_fields_by_default(): void
    {
        $result = $this->service->build(
            self::createVariation(costPrice: 15.00),
            self::createContext(command: self::createCommand(noSupplier: false)),
        );

        self::assertNotNull($result->supplierId);
        self::assertNotNull($result->purchasePrice);
        self::assertSame('SUP-CODE', $result->supplierCode);
    }

    #[Test]
    public function it_nullifies_all_supplier_fields_when_no_supplier_enabled(): void
    {
        $result = $this->service->build(
            self::createVariation(costPrice: 15.00),
            self::createContext(command: self::createCommand(noSupplier: true)),
        );

        self::assertNull($result->supplierId);
        self::assertNull($result->purchasePrice);
        self::assertNull($result->supplierCode);
    }

    /*
    |--------------------------------------------------------------------------
    | Extended Properties Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_sets_shop_id_extended_property(): void
    {
        $variation = self::createVariation(id: 42);

        $result = $this->service->build(
            $variation,
            self::createContext(),
        );

        self::assertSame(['ShopID' => '42'], $result->extendedProperties);
    }

    /*
    |--------------------------------------------------------------------------
    | Category Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_template_category_id(): void
    {
        $result = $this->service->build(
            self::createVariation(),
            self::createContext(),
        );

        self::assertSame('550e8400-e29b-41d4-a716-446655440001', $result->categoryId->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures & Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param list<ProductVariation>|null $standardSignVariations
     */
    private static function createContext(
        ?Product $product = null,
        ?StockItemFull $template = null,
        ?GenerateVariantSkusCommand $command = null,
        ?array $standardSignVariations = null,
    ): VariationProcessingContextDTO {
        return new VariationProcessingContextDTO(
            product: $product ?? self::createProduct(),
            template: $template ?? self::createTemplate(),
            command: $command ?? self::createCommand(),
            standardSignVariations: $standardSignVariations,
        );
    }

    /**
     * @param list<ProductVariationOption> $options
     */
    private static function createVariation(
        int $id = 1001,
        ?float $costPrice = 15.00,
        ?string $mpn = null,
        array $options = [],
    ): ProductVariation {
        return new ProductVariation(
            id: $id,
            productExternalId: 12345,
            sku: null,
            price: 29.99,
            costPrice: $costPrice,
            salePrice: null,
            stock: 10,
            weight: null,
            gtin: null,
            mpn: $mpn,
            imageIndex: null,
            options: $options,
        );
    }

    private static function createProduct(
        bool $vatExclusive = false,
        ?float $costPrice = 15.00,
    ): Product {
        return new Product(
            id: 12345,
            sku: 'PARENT-SKU',
            gtin: null,
            title: 'Test Product',
            description: null,
            slug: 'test-product',
            url: 'https://example.com/test-product',
            price: 29.99,
            costPrice: $costPrice,
            salePrice: null,
            comparePrice: null,
            stock: 0,
            isActive: true,
            vatExclusive: $vatExclusive,
            vatRelief: false,
            weight: null,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [1],
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

    private static function createTemplate(): StockItemFull
    {
        return new StockItemFull(
            stockItemId: '550e8400-e29b-41d4-a716-446655440020',
            sku: 'TEMPLATE-SKU',
            title: 'Template Item',
            barcode: '',
            quantity: 100,
            available: 100,
            inOrder: 0,
            due: 0,
            minimumLevel: 5,
            jit: false,
            purchasePrice: 15.00,
            retailPrice: 29.99,
            taxRate: 20.0,
            weight: Weight::zero(),
            dimensions: Dimensions::zero(),
            isComposite: false,
            categoryId: '550e8400-e29b-41d4-a716-446655440001',
            categoryName: 'Test Category',
            createdAt: null,
            extendedProperties: [],
            suppliers: [new StockItemSupplier(
                supplierId: new Guid('550e8400-e29b-41d4-a716-446655440002'),
                supplierName: 'Default Supplier',
                code: 'SUP-CODE',
                supplierBarcode: null,
                purchasePrice: Money::exclusive(15.00),
                isDefault: true,
                leadTime: null,
                supplierCurrency: 'GBP',
                minPrice: null,
                maxPrice: null,
                averagePrice: null,
            )],
        );
    }

    private static function createCommand(
        bool $copyParentMpn = false,
        bool $noSupplier = false,
        bool $isStandardSign = false,
    ): GenerateVariantSkusCommand {
        return new GenerateVariantSkusCommand(
            productId: IntId::from(12345),
            templateSku: Sku::fromTrusted('TEMPLATE-SKU'),
            copyParentMpn: $copyParentMpn,
            noSupplier: $noSupplier,
            isStandardSign: $isStandardSign,
        );
    }

    private static function option(string $name, string $value): ProductVariationOption
    {
        return new ProductVariationOption(
            optionId: 1,
            optionName: $name,
            valueId: 1,
            valueName: $value,
        );
    }
}
