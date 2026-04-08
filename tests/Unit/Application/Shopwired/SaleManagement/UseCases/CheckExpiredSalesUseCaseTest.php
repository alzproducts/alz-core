<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\SaleManagement\UseCases;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Shopwired\PricingUpdate\UseCases\UpdateProductSellingPricesUseCase;
use App\Application\Shopwired\SaleManagement\UseCases\CheckExpiredSalesUseCase;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use App\Domain\Catalog\Product\Enums\SaleRemovalReason;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(CheckExpiredSalesUseCase::class)]
final class CheckExpiredSalesUseCaseTest extends TestCase
{
    private ProductRepositoryInterface&MockInterface $productRepo;

    private UpdateProductSellingPricesUseCase&MockInterface $updatePricesUseCase;

    private LoggerInterface&MockInterface $logger;

    private CheckExpiredSalesUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->productRepo = Mockery::mock(ProductRepositoryInterface::class);
        $this->updatePricesUseCase = Mockery::mock(UpdateProductSellingPricesUseCase::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new CheckExpiredSalesUseCase(
            productRepository: $this->productRepo,
            updatePricesUseCase: $this->updatePricesUseCase,
            logger: $this->logger,
        );
    }

    // ========================================================================
    // No Products
    // ========================================================================

    #[Test]
    public function returns_zero_counts_when_no_products_on_sale(): void
    {
        $this->productRepo->shouldReceive('getProductsOnSale')->once()->andReturn([]);

        $result = $this->useCase->execute();

        self::assertSame(['checked' => 0, 'removed' => 0, 'failed' => 0], $result);
    }

    // ========================================================================
    // Condition 1: Product Inactive
    // ========================================================================

    #[Test]
    public function removes_inactive_product(): void
    {
        $product = self::createProduct(id: 1, sku: 'SKU-001', isActive: false);
        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$product]);

        $this->updatePricesUseCase->shouldReceive('execute')
            ->once()
            ->with(
                Mockery::on(static fn(array $commands): bool => \count($commands) === 1 && $commands[0]->sku->value === 'SKU-001'),
                Mockery::on(static fn(SaleSettings $s): bool => $s->removalReason === SaleRemovalReason::ProductInactive),
            );

        $result = $this->useCase->execute();

        self::assertSame(1, $result['checked']);
        self::assertSame(1, $result['removed']);
        self::assertSame(0, $result['failed']);
    }

    // ========================================================================
    // Condition 2: Sale End Date Reached
    // ========================================================================

    #[Test]
    public function removes_product_with_past_end_date(): void
    {
        $product = self::createProduct(id: 2, sku: 'SKU-002', customFields: [
            'sale_date_end' => '2026-03-01',
        ]);
        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$product]);

        $this->updatePricesUseCase->shouldReceive('execute')
            ->once()
            ->with(
                Mockery::type('array'),
                Mockery::on(static fn(SaleSettings $s): bool => $s->removalReason === SaleRemovalReason::EndDateReached),
            );

        $result = $this->useCase->execute();

        self::assertSame(1, $result['removed']);
    }

    #[Test]
    public function skips_product_with_future_end_date(): void
    {
        $product = self::createProduct(id: 3, sku: 'SKU-003', customFields: [
            'sale_date_end' => '2099-12-31',
        ]);
        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$product]);
        $this->updatePricesUseCase->shouldNotReceive('execute');

        $result = $this->useCase->execute();

        self::assertSame(1, $result['checked']);
        self::assertSame(0, $result['removed']);
    }

    #[Test]
    public function skips_product_with_malformed_end_date(): void
    {
        $product = self::createProduct(id: 4, sku: 'SKU-004', customFields: [
            'sale_date_end' => 'not-a-date',
        ]);
        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$product]);
        $this->updatePricesUseCase->shouldNotReceive('execute');

        $result = $this->useCase->execute();

        self::assertSame(0, $result['removed']);
    }

    #[Test]
    public function skips_product_with_no_end_date(): void
    {
        $product = self::createProduct(id: 5, sku: 'SKU-005');
        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$product]);
        $this->updatePricesUseCase->shouldNotReceive('execute');

        $result = $this->useCase->execute();

        self::assertSame(0, $result['removed']);
    }

    // ========================================================================
    // Condition 3: Out of Stock + Discontinued
    // ========================================================================

    #[Test]
    public function removes_out_of_stock_discontinued_product(): void
    {
        $product = self::createProduct(id: 6, sku: 'SKU-006', stock: 0, customFields: [
            'discontinued' => 'yes',
        ]);
        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$product]);

        $this->updatePricesUseCase->shouldReceive('execute')
            ->once()
            ->with(
                Mockery::type('array'),
                Mockery::on(static fn(SaleSettings $s): bool => $s->removalReason === SaleRemovalReason::OutOfStockDiscontinued),
            );

        $result = $this->useCase->execute();

        self::assertSame(1, $result['removed']);
    }

    #[Test]
    public function skips_in_stock_discontinued_product(): void
    {
        $product = self::createProduct(id: 7, sku: 'SKU-007', stock: 10, customFields: [
            'discontinued' => 'yes',
        ]);
        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$product]);
        $this->updatePricesUseCase->shouldNotReceive('execute');

        $result = $this->useCase->execute();

        self::assertSame(0, $result['removed']);
    }

    #[Test]
    public function skips_out_of_stock_not_discontinued_product(): void
    {
        $product = self::createProduct(id: 8, sku: 'SKU-008', stock: 0);
        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$product]);
        $this->updatePricesUseCase->shouldNotReceive('execute');

        $result = $this->useCase->execute();

        self::assertSame(0, $result['removed']);
    }

    // ========================================================================
    // Condition 4: Sale Units Sold (Stock <= Threshold)
    // ========================================================================

    #[Test]
    public function removes_product_when_stock_at_threshold(): void
    {
        $product = self::createProduct(id: 9, sku: 'SKU-009', stock: 5, customFields: [
            'sale_ends_stock' => '5',
        ]);
        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$product]);

        $this->updatePricesUseCase->shouldReceive('execute')
            ->once()
            ->with(
                Mockery::type('array'),
                Mockery::on(static fn(SaleSettings $s): bool => $s->removalReason === SaleRemovalReason::SaleUnitsSold),
            );

        $result = $this->useCase->execute();

        self::assertSame(1, $result['removed']);
    }

    #[Test]
    public function removes_product_when_stock_below_threshold(): void
    {
        $product = self::createProduct(id: 10, sku: 'SKU-010', stock: 2, customFields: [
            'sale_ends_stock' => '5',
        ]);
        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$product]);

        $this->updatePricesUseCase->shouldReceive('execute')->once();

        $result = $this->useCase->execute();

        self::assertSame(1, $result['removed']);
    }

    #[Test]
    public function skips_product_when_stock_above_threshold(): void
    {
        $product = self::createProduct(id: 11, sku: 'SKU-011', stock: 10, customFields: [
            'sale_ends_stock' => '5',
        ]);
        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$product]);
        $this->updatePricesUseCase->shouldNotReceive('execute');

        $result = $this->useCase->execute();

        self::assertSame(0, $result['removed']);
    }

    #[Test]
    public function skips_product_with_non_numeric_threshold(): void
    {
        $product = self::createProduct(id: 12, sku: 'SKU-012', stock: 2, customFields: [
            'sale_ends_stock' => 'abc',
        ]);
        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$product]);
        $this->updatePricesUseCase->shouldNotReceive('execute');

        $result = $this->useCase->execute();

        self::assertSame(0, $result['removed']);
    }

    #[Test]
    public function skips_product_with_empty_threshold(): void
    {
        $product = self::createProduct(id: 13, sku: 'SKU-013', stock: 2, customFields: [
            'sale_ends_stock' => '',
        ]);
        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$product]);
        $this->updatePricesUseCase->shouldNotReceive('execute');

        $result = $this->useCase->execute();

        self::assertSame(0, $result['removed']);
    }

    // ========================================================================
    // Error Handling
    // ========================================================================

    #[Test]
    public function increments_failed_count_when_product_has_no_sku(): void
    {
        $product = self::createProduct(id: 14, sku: null, isActive: false);
        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$product]);
        $this->updatePricesUseCase->shouldNotReceive('execute');

        $this->logger->shouldReceive('warning')
            ->once()
            ->with('Cannot auto-remove sale: product has no SKU', Mockery::on(
                static fn(array $ctx): bool => $ctx['product_id'] === 14 && $ctx['reason'] === 'product_inactive',
            ));

        $result = $this->useCase->execute();

        self::assertSame(1, $result['checked']);
        self::assertSame(0, $result['removed']);
        self::assertSame(1, $result['failed']);
    }

    #[Test]
    public function increments_failed_count_when_product_has_empty_sku(): void
    {
        $product = self::createProduct(id: 15, sku: '', isActive: false);
        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$product]);
        $this->updatePricesUseCase->shouldNotReceive('execute');

        $result = $this->useCase->execute();

        self::assertSame(1, $result['failed']);
    }

    #[Test]
    public function continues_processing_when_individual_removal_fails(): void
    {
        $failing = self::createProduct(id: 16, sku: 'FAIL-001', isActive: false);
        $succeeding = self::createProduct(id: 17, sku: 'OK-001', isActive: false);

        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$failing, $succeeding]);

        $this->updatePricesUseCase->shouldReceive('execute')
            ->once()
            ->with(
                Mockery::on(static fn(array $c): bool => $c[0]->sku->value === 'FAIL-001'),
                Mockery::type(SaleSettings::class),
            )
            ->andThrow(new ExternalServiceUnavailableException('ShopWired'));

        $this->updatePricesUseCase->shouldReceive('execute')
            ->once()
            ->with(
                Mockery::on(static fn(array $c): bool => $c[0]->sku->value === 'OK-001'),
                Mockery::type(SaleSettings::class),
            );

        $this->logger->shouldReceive('error')
            ->once()
            ->with('Failed to auto-remove product from sale', Mockery::on(
                static fn(array $ctx): bool => $ctx['product_id'] === 16 && $ctx['sku'] === 'FAIL-001',
            ));

        $result = $this->useCase->execute();

        self::assertSame(2, $result['checked']);
        self::assertSame(1, $result['removed']);
        self::assertSame(1, $result['failed']);
    }

    // ========================================================================
    // Condition Priority (first match wins)
    // ========================================================================

    #[Test]
    public function inactive_takes_priority_over_end_date(): void
    {
        $product = self::createProduct(id: 18, sku: 'SKU-018', isActive: false, customFields: [
            'sale_date_end' => '2026-01-01',
        ]);
        $this->productRepo->shouldReceive('getProductsOnSale')->andReturn([$product]);

        $this->updatePricesUseCase->shouldReceive('execute')
            ->once()
            ->with(
                Mockery::type('array'),
                Mockery::on(static fn(SaleSettings $s): bool => $s->removalReason === SaleRemovalReason::ProductInactive),
            );

        $result = $this->useCase->execute();

        self::assertSame(1, $result['removed']);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * @param array<string, string> $customFields Raw name => value pairs, converted to typed StringCustomFieldValue
     */
    private static function createProduct(
        int $id,
        ?string $sku,
        bool $isActive = true,
        int $stock = 100,
        array $customFields = [],
    ): Product {
        $typedFields = self::buildTypedCustomFields($customFields);

        return new Product(
            id: $id,
            sku: $sku,
            gtin: null,
            title: "Product {$id}",
            description: null,
            slug: "product-{$id}",
            url: "https://example.com/product-{$id}",
            price: 25.00,
            costPrice: null,
            salePrice: 20.00,
            comparePrice: null,
            stock: $stock,
            isActive: $isActive,
            vatExclusive: false,
            vatRelief: false,
            weight: null,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [],
            variations: null,
            images: [],
            rawCustomFields: $customFields,
            customFields: $typedFields,
            rawFilters: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
        );
    }

    /**
     * @param array<string, string> $rawFields
     *
     * @return list<AbstractCustomFieldValue>
     */
    private static function buildTypedCustomFields(array $rawFields): array
    {
        $typed = [];
        $id = 0;

        foreach ($rawFields as $name => $value) {
            $typed[] = new StringCustomFieldValue(
                definition: new CustomFieldDefinition(
                    id: ++$id,
                    name: $name,
                    type: CustomFieldType::Text,
                    label: $name,
                    itemType: CustomFieldItemType::Product,
                    sortOrder: null,
                    allowedValues: null,
                ),
                value: $value,
            );
        }

        return $typed;
    }
}
