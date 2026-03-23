<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\SaleManagement\UseCases;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\SaleReconciliationDispatcherInterface;
use App\Application\Contracts\Shopwired\SaleSettingsRepositoryInterface;
use App\Application\Shopwired\SaleManagement\Resolvers\ProductSaleStateResolver;
use App\Application\Shopwired\SaleManagement\Results\ProductSaleStateResult;
use App\Application\Shopwired\SaleManagement\Results\SkuSaleStateResult;
use App\Application\Shopwired\SaleManagement\UseCases\ReconcileProductSaleStateUseCase;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(ReconcileProductSaleStateUseCase::class)]
final class ReconcileProductSaleStateUseCaseTest extends TestCase
{
    private const int SALE_CATEGORY_ID = 999;

    private ProductRepositoryInterface&MockInterface $productRepo;

    private SaleReconciliationDispatcherInterface&MockInterface $dispatcher;

    private SaleSettingsRepositoryInterface&MockInterface $saleSettingsRepo;

    private ProductSaleStateResolver&MockInterface $specification;

    private LoggerInterface&MockInterface $logger;

    private ReconcileProductSaleStateUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->productRepo = Mockery::mock(ProductRepositoryInterface::class);
        $this->dispatcher = Mockery::mock(SaleReconciliationDispatcherInterface::class);
        $this->saleSettingsRepo = Mockery::mock(SaleSettingsRepositoryInterface::class);
        $this->specification = Mockery::mock(ProductSaleStateResolver::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new ReconcileProductSaleStateUseCase(
            productRepo: $this->productRepo,
            dispatcher: $this->dispatcher,
            saleSettingsRepo: $this->saleSettingsRepo,
            specification: $this->specification,
            logger: $this->logger,
            saleCategoryId: self::SALE_CATEGORY_ID,
        );
    }

    // ========================================================================
    // Fast-path: No drift
    // ========================================================================

    #[Test]
    public function returns_early_when_no_drift_detected(): void
    {
        $productId = IntId::from(42);

        $this->productRepo->shouldReceive('hasSaleStateDrift')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 42),
                self::SALE_CATEGORY_ID,
            )
            ->andReturnFalse();

        // Must NOT load product or dispatch anything
        $this->productRepo->shouldNotReceive('getProduct');
        $this->dispatcher->shouldNotReceive('dispatchAddToSale');
        $this->dispatcher->shouldNotReceive('dispatchRemoveFromSale');
        $this->dispatcher->shouldNotReceive('dispatchUpdateSaleState');
        $this->saleSettingsRepo->shouldNotReceive('findByProduct');

        $this->useCase->execute($productId);
    }

    // ========================================================================
    // Drift detected: needs add to sale (settings already in DB)
    // ========================================================================

    #[Test]
    public function dispatches_add_to_sale_when_drift_needs_add_and_settings_in_db(): void
    {
        $productId = IntId::from(10);
        $product = self::createProduct(id: 10, sku: 'SKU-010');
        $dbSettings = new SaleSettings(saleReason: 'Flash Sale');

        $sku1 = Sku::fromTrusted('SKU-010');
        $sku2 = Sku::fromTrusted('SKU-010-VAR');

        $result = new ProductSaleStateResult(
            productId: $productId,
            shouldBeOnSale: true,
            needsAddToSale: true,
            needsRemoveFromSale: false,
            skuSaleStates: [
                new SkuSaleStateResult(sku: $sku1, shouldBeInSale: true),
                new SkuSaleStateResult(sku: $sku2, shouldBeInSale: true),
            ],
        );

        $this->productRepo->shouldReceive('hasSaleStateDrift')->once()->andReturnTrue();
        $this->productRepo->shouldReceive('getProduct')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 10))
            ->andReturn($product);

        $this->specification->shouldReceive('evaluate')
            ->once()
            ->with($product)
            ->andReturn($result);

        // DB has existing settings — no save needed
        $this->saleSettingsRepo->shouldReceive('findByProduct')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 10))
            ->andReturn($dbSettings);
        $this->saleSettingsRepo->shouldNotReceive('save');

        $this->dispatcher->shouldReceive('dispatchAddToSale')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 10),
                self::SALE_CATEGORY_ID,
            );

        $this->dispatcher->shouldNotReceive('dispatchRemoveFromSale');

        $this->dispatcher->shouldReceive('dispatchUpdateSaleState')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 10),
                Mockery::on(static fn(Sku $s): bool => $s->value === 'SKU-010'),
            );

        $this->dispatcher->shouldReceive('dispatchUpdateSaleState')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 10),
                Mockery::on(static fn(Sku $s): bool => $s->value === 'SKU-010-VAR'),
            );

        $this->useCase->execute($productId);
    }

    // ========================================================================
    // Drift detected: needs remove from sale
    // ========================================================================

    #[Test]
    public function dispatches_remove_from_sale_and_sku_updates_when_drift_needs_remove(): void
    {
        $productId = IntId::from(20);
        $product = self::createProduct(id: 20, sku: 'SKU-020');

        $sku = Sku::fromTrusted('SKU-020');

        $result = new ProductSaleStateResult(
            productId: $productId,
            shouldBeOnSale: false,
            needsAddToSale: false,
            needsRemoveFromSale: true,
            skuSaleStates: [
                new SkuSaleStateResult(sku: $sku, shouldBeInSale: false),
            ],
        );

        $this->productRepo->shouldReceive('hasSaleStateDrift')->once()->andReturnTrue();
        $this->productRepo->shouldReceive('getProduct')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 20))
            ->andReturn($product);

        $this->specification->shouldReceive('evaluate')
            ->once()
            ->with($product)
            ->andReturn($result);

        $this->dispatcher->shouldNotReceive('dispatchAddToSale');
        $this->saleSettingsRepo->shouldNotReceive('findByProduct');

        $this->dispatcher->shouldReceive('dispatchRemoveFromSale')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 20),
                self::SALE_CATEGORY_ID,
            );

        $this->dispatcher->shouldReceive('dispatchUpdateSaleState')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 20),
                Mockery::on(static fn(Sku $s): bool => $s->value === 'SKU-020'),
            );

        $this->useCase->execute($productId);
    }

    // ========================================================================
    // Drift detected but no correction needed
    // ========================================================================

    #[Test]
    public function does_not_dispatch_when_specification_says_no_correction_needed(): void
    {
        $productId = IntId::from(30);
        $product = self::createProduct(id: 30, sku: 'SKU-030');

        // skuSaleStates is always populated by the resolver, but should NOT
        // trigger dispatches when no product-level correction is needed
        $result = new ProductSaleStateResult(
            productId: $productId,
            shouldBeOnSale: true,
            needsAddToSale: false,
            needsRemoveFromSale: false,
            skuSaleStates: [
                new SkuSaleStateResult(sku: Sku::fromTrusted('SKU-030'), shouldBeInSale: true),
            ],
        );

        $this->productRepo->shouldReceive('hasSaleStateDrift')->once()->andReturnTrue();
        $this->productRepo->shouldReceive('getProduct')->once()->andReturn($product);
        $this->specification->shouldReceive('evaluate')->once()->andReturn($result);

        $this->dispatcher->shouldNotReceive('dispatchAddToSale');
        $this->dispatcher->shouldNotReceive('dispatchRemoveFromSale');
        $this->dispatcher->shouldNotReceive('dispatchUpdateSaleState');
        $this->saleSettingsRepo->shouldNotReceive('findByProduct');

        $this->useCase->execute($productId);
    }

    // ========================================================================
    // Drift detected, needs add, no DB row → reconstruct from custom fields and persist
    // ========================================================================

    #[Test]
    public function reconstructs_and_persists_sale_settings_from_custom_fields_when_no_db_row(): void
    {
        $productId = IntId::from(40);
        $product = self::createProduct(id: 40, sku: 'SKU-040', rawCustomFields: [
            'sale_reason' => 'Clearance',
            'sale_comments' => 'End of line',
            'sale_date_end' => '2099-12-31',
            'sale_ends_stock' => '10',
        ]);

        $sku = Sku::fromTrusted('SKU-040');

        $result = new ProductSaleStateResult(
            productId: $productId,
            shouldBeOnSale: true,
            needsAddToSale: true,
            needsRemoveFromSale: false,
            skuSaleStates: [
                new SkuSaleStateResult(sku: $sku, shouldBeInSale: true),
            ],
        );

        $this->productRepo->shouldReceive('hasSaleStateDrift')->once()->andReturnTrue();
        $this->productRepo->shouldReceive('getProduct')->once()->andReturn($product);
        $this->specification->shouldReceive('evaluate')->once()->andReturn($result);

        // No DB row — triggers fallback build + persist
        $this->saleSettingsRepo->shouldReceive('findByProduct')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 40))
            ->andReturnNull();

        $this->saleSettingsRepo->shouldReceive('save')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 40),
                Mockery::on(static fn(SaleSettings $s): bool => $s->saleReason === 'Clearance'
                    && $s->saleComments === 'End of line'
                    && $s->saleEndDate instanceof DateTimeImmutable
                    && $s->saleEndDate->format('Y-m-d') === '2099-12-31'
                    && $s->saleEndsStock === 10),
            );

        $this->dispatcher->shouldReceive('dispatchAddToSale')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 40),
                self::SALE_CATEGORY_ID,
            );

        $this->dispatcher->shouldReceive('dispatchUpdateSaleState')->once();

        $this->useCase->execute($productId);
    }

    // ========================================================================
    // Drift detected, needs add, no DB row, no custom fields → fallback reason
    // ========================================================================

    #[Test]
    public function uses_fallback_reconciliation_reason_when_no_db_row_and_no_custom_fields(): void
    {
        $productId = IntId::from(50);
        $product = self::createProduct(id: 50, sku: 'SKU-050', rawCustomFields: []);

        $sku = Sku::fromTrusted('SKU-050');

        $result = new ProductSaleStateResult(
            productId: $productId,
            shouldBeOnSale: true,
            needsAddToSale: true,
            needsRemoveFromSale: false,
            skuSaleStates: [
                new SkuSaleStateResult(sku: $sku, shouldBeInSale: true),
            ],
        );

        $this->productRepo->shouldReceive('hasSaleStateDrift')->once()->andReturnTrue();
        $this->productRepo->shouldReceive('getProduct')->once()->andReturn($product);
        $this->specification->shouldReceive('evaluate')->once()->andReturn($result);

        $this->saleSettingsRepo->shouldReceive('findByProduct')
            ->once()
            ->andReturnNull();

        $this->saleSettingsRepo->shouldReceive('save')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(static fn(SaleSettings $s): bool => $s->saleReason === 'Reconciliation'
                    && $s->saleComments === null
                    && $s->saleEndDate === null
                    && $s->saleEndsStock === null),
            );

        $this->dispatcher->shouldReceive('dispatchAddToSale')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 50),
                self::SALE_CATEGORY_ID,
            );

        $this->dispatcher->shouldReceive('dispatchUpdateSaleState')->once();

        $this->useCase->execute($productId);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * @param array<string, mixed> $rawCustomFields
     */
    private static function createProduct(
        int $id,
        ?string $sku,
        bool $isActive = true,
        int $stock = 100,
        array $rawCustomFields = [],
    ): Product {
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
            rawCustomFields: $rawCustomFields,
            customFields: [],
            rawFilters: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
        );
    }
}
