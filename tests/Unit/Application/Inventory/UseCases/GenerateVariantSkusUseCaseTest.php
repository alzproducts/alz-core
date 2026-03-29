<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Inventory\UseCases;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Inventory\Commands\GenerateVariantSkusCommand;
use App\Application\Inventory\DTOs\VariationProcessingContextDTO;
use App\Application\Inventory\Params\CreateStockItemParams;
use App\Application\Inventory\Services\GenerateStockItemFromVariationService;
use App\Application\Inventory\Services\StockItemParamsBuilderService;
use App\Application\Inventory\UseCases\GenerateVariantSkusUseCase;
use App\Application\Shopwired\Services\ProductSyncService;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Inventory\InvalidTemplateException;
use App\Domain\Inventory\Events\VariantSkusGeneratedEvent;
use App\Domain\Inventory\ValueObjects\Dimensions;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\Inventory\ValueObjects\StockItemSupplier;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\TaxRate;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Tests for GenerateVariantSkusUseCase branching logic.
 *
 * Per TestingStrategy.md: Test orchestration decisions (early returns,
 * filtering, validation), not internal data transformation.
 */
#[CoversClass(GenerateVariantSkusUseCase::class)]
final class GenerateVariantSkusUseCaseTest extends TestCase
{
    private InventoryClientInterface&MockInterface $inventoryClient;

    private ProductSyncService&MockInterface $productSyncService;

    private GenerateStockItemFromVariationService&MockInterface $stockItemGenerator;

    private StockItemParamsBuilderService&MockInterface $paramsBuilder;

    private ProductRepositoryInterface&MockInterface $productRepository;

    private LoggerInterface&MockInterface $logger;

    private GenerateVariantSkusUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([VariantSkusGeneratedEvent::class]);

        $this->inventoryClient = Mockery::mock(InventoryClientInterface::class);
        $this->productSyncService = Mockery::mock(ProductSyncService::class);
        $this->stockItemGenerator = Mockery::mock(GenerateStockItemFromVariationService::class);
        $this->paramsBuilder = Mockery::mock(StockItemParamsBuilderService::class);
        $this->productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new GenerateVariantSkusUseCase(
            $this->inventoryClient,
            $this->productSyncService,
            $this->stockItemGenerator,
            $this->paramsBuilder,
            $this->productRepository,
            $this->logger,
            standardSignProductId: 99999,
            eventDispatcher: \app(Dispatcher::class),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Early Return Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_no_variations_result_when_product_has_none(): void
    {
        $command = $this->createCommand();
        $product = $this->createProduct(variations: []);

        $this->productSyncService->shouldReceive('refreshById')
            ->once()
            ->with(12345)
            ->andReturn($product);

        // Template never fetched when no variations
        $this->inventoryClient->shouldNotReceive('getStockItemFull');

        // No generator calls
        $this->stockItemGenerator->shouldNotReceive('generate');

        $result = $this->useCase->execute($command);

        $this->assertSame(0, $result->total);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->failed);
    }

    #[Test]
    public function it_returns_all_skipped_when_all_variations_have_skus(): void
    {
        $command = $this->createCommand();
        $variations = [
            $this->createVariation(id: 1, sku: 'EXISTING-1'),
            $this->createVariation(id: 2, sku: 'EXISTING-2'),
            $this->createVariation(id: 3, sku: 'EXISTING-3'),
        ];
        $product = $this->createProduct(variations: $variations);
        $template = $this->createTemplate(hasSupplier: true);

        $this->productSyncService->shouldReceive('refreshById')
            ->once()
            ->andReturn($product);

        $this->inventoryClient->shouldReceive('getStockItemFull')
            ->once()
            ->andReturn($template);

        // No generator calls - all have SKUs
        $this->stockItemGenerator->shouldNotReceive('generate');

        $result = $this->useCase->execute($command);

        $this->assertSame(3, $result->total);
        $this->assertSame(3, $result->skipped);
        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->failed);
        $this->assertTrue($result->allSucceeded());
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_invalid_template_exception_when_no_default_supplier(): void
    {
        $command = $this->createCommand();
        $variations = [
            $this->createVariation(id: 1, sku: null),
        ];
        $product = $this->createProduct(variations: $variations);
        $template = $this->createTemplate(hasSupplier: false);

        $this->productSyncService->shouldReceive('refreshById')
            ->once()
            ->andReturn($product);

        $this->inventoryClient->shouldReceive('getStockItemFull')
            ->once()
            ->andReturn($template);

        // Generator never called due to validation failure
        $this->stockItemGenerator->shouldNotReceive('generate');

        $this->expectException(InvalidTemplateException::class);
        $this->expectExceptionMessage('Invalid template stock item');

        $this->useCase->execute($command);
    }

    /*
    |--------------------------------------------------------------------------
    | Filtering Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_processes_only_sku_less_variations(): void
    {
        $command = $this->createCommand();
        $variations = [
            $this->createVariation(id: 1, sku: 'HAS-SKU'),     // Skipped
            $this->createVariation(id: 2, sku: null),          // Processed
            $this->createVariation(id: 3, sku: 'ALSO-HAS'),    // Skipped
            $this->createVariation(id: 4, sku: null),          // Processed
        ];
        $product = $this->createProduct(variations: $variations);
        $template = $this->createTemplate(hasSupplier: true);

        $this->productSyncService->shouldReceive('refreshById')
            ->once()
            ->andReturn($product);

        $this->inventoryClient->shouldReceive('getStockItemFull')
            ->once()
            ->andReturn($template);

        // Params builder returns a stub for any variation
        $this->paramsBuilder->shouldReceive('build')
            ->andReturn($this->createStubParams());

        // Only called for variations 2 and 4 (the SKU-less ones)
        $this->stockItemGenerator->shouldReceive('generate')
            ->twice()
            ->andReturnUsing(function ($params, $variationId) {
                // Verify only SKU-less variation IDs are processed
                $this->assertContains($variationId, [2, 4]);

                return Sku::fromTrusted('NEW-' . $variationId);
            });

        $this->productSyncService->shouldReceive('refreshById')->once();

        $result = $this->useCase->execute($command);

        $this->assertSame(4, $result->total);
        $this->assertSame(2, $result->skipped);
        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->failed);
        $this->assertSame(['NEW-2', 'NEW-4'], $result->createdVariants);
    }

    /*
    |--------------------------------------------------------------------------
    | Partial Failure Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_continues_processing_after_individual_variation_failure(): void
    {
        $command = $this->createCommand();
        $variations = [
            $this->createVariation(id: 1, sku: null),
            $this->createVariation(id: 2, sku: null),
            $this->createVariation(id: 3, sku: null),
        ];
        $product = $this->createProduct(variations: $variations);
        $template = $this->createTemplate(hasSupplier: true);

        $this->productSyncService->shouldReceive('refreshById')
            ->once()
            ->andReturn($product);

        $this->inventoryClient->shouldReceive('getStockItemFull')
            ->once()
            ->andReturn($template);

        // Params builder returns a stub for any variation
        $this->paramsBuilder->shouldReceive('build')
            ->andReturn($this->createStubParams());

        // First succeeds, second fails, third succeeds
        $this->stockItemGenerator->shouldReceive('generate')
            ->times(3)
            ->andReturnUsing(static function ($params, $variationId): Sku {
                if ($variationId === 2) {
                    throw new ExternalServiceUnavailableException('Linnworks');
                }

                return Sku::fromTrusted('SUCCESS-' . $variationId);
            });

        $this->productSyncService->shouldReceive('refreshById')->once();

        $result = $this->useCase->execute($command);

        $this->assertSame(3, $result->total);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(2, $result->created);
        $this->assertSame(1, $result->failed);
        $this->assertSame(['SUCCESS-1', 'SUCCESS-3'], $result->createdVariants);
        $this->assertSame([2], $result->failedVariationIds);
        $this->assertTrue($result->hasFailures());
    }

    /*
    |--------------------------------------------------------------------------
    | Standard Sign Flag Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_loads_standard_sign_product_when_flag_enabled(): void
    {
        $command = $this->createCommand(isStandardSign: true);
        $variations = [$this->createVariation(id: 1, sku: null)];
        $product = $this->createProduct(variations: $variations);
        $template = $this->createTemplate(hasSupplier: true);

        // Standard sign product fetched from local DB
        $standardProduct = $this->createProduct(variations: [
            $this->createVariation(id: 100, sku: 'STD-001'),
        ]);

        $this->productSyncService->shouldReceive('refreshById')
            ->andReturn($product);

        $this->inventoryClient->shouldReceive('getStockItemFull')
            ->once()
            ->andReturn($template);

        // Product repository IS called for standard sign product
        $this->productRepository->shouldReceive('getProduct')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 99999))
            ->andReturn($standardProduct);

        $this->paramsBuilder->shouldReceive('build')
            ->andReturn($this->createStubParams());

        $this->stockItemGenerator->shouldReceive('generate')
            ->once()
            ->andReturn(Sku::fromTrusted('NEW-1'));

        $result = $this->useCase->execute($command);

        $this->assertSame(1, $result->created);
    }

    #[Test]
    public function it_does_not_load_standard_sign_product_when_flag_disabled(): void
    {
        $command = $this->createCommand(isStandardSign: false);
        $variations = [$this->createVariation(id: 1, sku: null)];
        $product = $this->createProduct(variations: $variations);
        $template = $this->createTemplate(hasSupplier: true);

        $this->productSyncService->shouldReceive('refreshById')
            ->andReturn($product);

        $this->inventoryClient->shouldReceive('getStockItemFull')
            ->once()
            ->andReturn($template);

        // Product repository NOT called when standard sign disabled
        $this->productRepository->shouldNotReceive('getProduct');

        $this->paramsBuilder->shouldReceive('build')
            ->andReturn($this->createStubParams());

        $this->stockItemGenerator->shouldReceive('generate')
            ->once()
            ->andReturn(Sku::fromTrusted('NEW-1'));

        $result = $this->useCase->execute($command);

        $this->assertSame(1, $result->created);
    }

    /*
    |--------------------------------------------------------------------------
    | No Supplier Flag Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_passes_no_supplier_command_to_params_builder(): void
    {
        $command = $this->createCommand(noSupplier: true);
        $variations = [$this->createVariation(id: 1, sku: null)];
        $product = $this->createProduct(variations: $variations);
        $template = $this->createTemplate(hasSupplier: true);

        $this->productSyncService->shouldReceive('refreshById')
            ->andReturn($product);

        $this->inventoryClient->shouldReceive('getStockItemFull')
            ->once()
            ->andReturn($template);

        // Verify noSupplier command is passed to params builder (which nullifies supplierId)
        $this->paramsBuilder->shouldReceive('build')
            ->once()
            ->withArgs(static fn($variation, VariationProcessingContextDTO $ctx): bool => $ctx->command->noSupplier === true)
            ->andReturn($this->createStubParams());

        $this->stockItemGenerator->shouldReceive('generate')
            ->once()
            ->andReturn(Sku::fromTrusted('NEW-1'));

        $result = $this->useCase->execute($command);

        $this->assertSame(1, $result->created);
    }

    /*
    |--------------------------------------------------------------------------
    | Event Dispatch Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_dispatches_notification_event_when_skus_created(): void
    {
        $command = $this->createCommand();
        $variations = [$this->createVariation(id: 1, sku: null)];
        $product = $this->createProduct(variations: $variations);
        $template = $this->createTemplate(hasSupplier: true);

        $this->productSyncService->shouldReceive('refreshById')
            ->andReturn($product);

        $this->inventoryClient->shouldReceive('getStockItemFull')
            ->once()
            ->andReturn($template);

        $this->paramsBuilder->shouldReceive('build')
            ->andReturn($this->createStubParams());

        $this->stockItemGenerator->shouldReceive('generate')
            ->once()
            ->andReturn(Sku::fromTrusted('NEW-1'));

        $this->useCase->execute($command);

        Event::assertDispatched(VariantSkusGeneratedEvent::class, static fn(VariantSkusGeneratedEvent $event): bool => $event->productId === 12345
                && $event->created === 1
                && $event->skipped === 0
                && $event->failed === 0);
    }

    #[Test]
    public function it_does_not_dispatch_notification_event_when_no_skus_created(): void
    {
        $command = $this->createCommand();
        $product = $this->createProduct(variations: []);

        $this->productSyncService->shouldReceive('refreshById')
            ->once()
            ->andReturn($product);

        $this->useCase->execute($command);

        Event::assertNotDispatched(VariantSkusGeneratedEvent::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Result Metadata Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_includes_product_title_in_result(): void
    {
        $command = $this->createCommand();
        $product = $this->createProduct(variations: []);

        $this->productSyncService->shouldReceive('refreshById')
            ->once()
            ->andReturn($product);

        $result = $this->useCase->execute($command);

        $this->assertSame('Test Product', $result->productTitle);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures & Helpers
    |--------------------------------------------------------------------------
    */

    private function createCommand(
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

    /**
     * @param list<ProductVariation> $variations
     */
    private function createProduct(array $variations): Product
    {
        return new Product(
            id: 12345,
            sku: 'PARENT-SKU',
            gtin: null,
            title: 'Test Product',
            description: null,
            slug: 'test-product',
            url: 'https://example.com/test-product',
            price: 29.99,
            costPrice: 15.00,
            salePrice: null,
            comparePrice: null,
            stock: 0,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            weight: null,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [1],
            variations: $variations,
            images: [],
            rawCustomFields: [],
            customFields: [],
            rawFilters: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }

    private function createVariation(int $id, ?string $sku): ProductVariation
    {
        return new ProductVariation(
            id: $id,
            productExternalId: 12345,
            sku: $sku,
            price: 29.99,
            costPrice: 15.00,
            salePrice: null,
            stock: 10,
            weight: null,
            gtin: null,
            mpn: null,
            imageIndex: null,
            options: [],
        );
    }

    private function createTemplate(bool $hasSupplier): StockItemFull
    {
        $suppliers = $hasSupplier
            ? [new StockItemSupplier(
                supplierId: '550e8400-e29b-41d4-a716-446655440002',
                supplierName: 'Default Supplier',
                code: 'SUP-CODE',
                supplierBarcode: null,
                purchasePrice: 15.00,
                isDefault: true,
                leadTime: null,
                supplierCurrency: 'GBP',
                minPrice: null,
                maxPrice: null,
                averagePrice: null,
            )]
            : [];

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
            suppliers: $suppliers,
        );
    }

    private function createStubParams(): CreateStockItemParams
    {
        return new CreateStockItemParams(
            categoryId: Guid::fromTrusted('550e8400-e29b-41d4-a716-446655440001'),
            title: 'Stub Item',
            retailPrice: Money::inclusive(29.99),
            taxRate: TaxRate::standard(),
            supplierId: Guid::fromTrusted('550e8400-e29b-41d4-a716-446655440002'),
        );
    }
}
