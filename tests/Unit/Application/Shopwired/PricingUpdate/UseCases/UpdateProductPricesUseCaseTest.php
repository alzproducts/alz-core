<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\PricingUpdate\UseCases;

use App\Application\Contracts\Shopwired\PriceUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Shopwired\PricingUpdate\Results\PriceUpdateClientResult;
use App\Application\Shopwired\PricingUpdate\UseCases\UpdateProductPricesUseCase;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\Events\ProductPricingUpdatedEvent;
use App\Domain\Catalog\Product\Events\SkuRetailPricingUpdatedEvent;
use App\Domain\Catalog\Product\ValueObjects\PriceUpdateItemResult;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Tests for UpdateProductPricesUseCase orchestration logic.
 *
 * Per TestingStrategy.md: Test workflow branches (pre-flight filtering,
 * API classification, event dispatch conditions), not internal data transformation.
 */
#[CoversClass(UpdateProductPricesUseCase::class)]
final class UpdateProductPricesUseCaseTest extends TestCase
{
    private PriceUpdateClientInterface&MockInterface $priceClient;

    private ProductRepositoryInterface&MockInterface $productRepo;

    private LoggerInterface&MockInterface $logger;

    private UpdateProductPricesUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([
            SkuRetailPricingUpdatedEvent::class,
            ProductPricingUpdatedEvent::class,
        ]);

        $this->priceClient = Mockery::mock(PriceUpdateClientInterface::class);
        $this->productRepo = Mockery::mock(ProductRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new UpdateProductPricesUseCase(
            priceClient: $this->priceClient,
            productRepo: $this->productRepo,
            events: Event::getFacadeRoot(),
            logger: $this->logger,
        );
    }

    // ========================================================================
    // Happy Path
    // ========================================================================

    #[Test]
    public function all_commands_validated_and_api_confirms_all(): void
    {
        $product = self::createProduct('MASTER-001', 20.00, null, [
            self::createVariation(1, 'VAR-001', 25.00, null),
        ]);
        $this->productRepo->shouldReceive('getProductByAnySku')->once()->andReturn($product);

        $this->priceClient->shouldReceive('updatePrices')
            ->once()
            ->andReturn(new PriceUpdateClientResult(
                results: [
                    new PriceUpdateItemResult(Sku::fromTrusted('MASTER-001'), updated: true, productId: IntId::fromTrusted(1)),
                    new PriceUpdateItemResult(Sku::fromTrusted('VAR-001'), updated: true, productId: IntId::fromTrusted(1)),
                ],
            ));

        $result = $this->useCase->execute([
            new UpdatePriceCommand(Sku::fromTrusted('MASTER-001'), price: Money::inclusive(30.00)),
            new UpdatePriceCommand(Sku::fromTrusted('VAR-001'), price: Money::inclusive(35.00)),
        ]);

        self::assertSame(2, $result->total);
        self::assertSame(2, $result->succeeded);
        self::assertSame([], $result->skipped);
        self::assertSame([], $result->permanentFailures);
        self::assertSame([], $result->temporaryFailures);

        Event::assertDispatched(SkuRetailPricingUpdatedEvent::class, 2);
        Event::assertDispatched(ProductPricingUpdatedEvent::class, 1);
    }

    // ========================================================================
    // Pre-flight: All Unchanged
    // ========================================================================

    #[Test]
    public function all_commands_unchanged_skips_api_call(): void
    {
        $product = self::createProduct('MASTER-001', 20.00, null);
        $this->productRepo->shouldReceive('getProductByAnySku')->once()->andReturn($product);

        // Same price as current — no change
        $result = $this->useCase->execute([
            new UpdatePriceCommand(Sku::fromTrusted('MASTER-001'), price: Money::inclusive(20.00)),
        ]);

        self::assertSame(1, $result->total);
        self::assertSame(0, $result->succeeded);
        self::assertCount(1, $result->skipped);
        self::assertSame('MASTER-001', $result->skipped[0]->sku->value);

        // API should NOT have been called
        $this->priceClient->shouldNotHaveBeenCalled();

        Event::assertNotDispatched(SkuRetailPricingUpdatedEvent::class);
        Event::assertNotDispatched(ProductPricingUpdatedEvent::class);
    }

    // ========================================================================
    // Pre-flight: SKU Ownership Failure
    // ========================================================================

    #[Test]
    public function unknown_sku_results_in_permanent_failure(): void
    {
        $product = self::createProduct('MASTER-001', 20.00, null);
        $this->productRepo->shouldReceive('getProductByAnySku')->once()->andReturn($product);

        $result = $this->useCase->execute([
            new UpdatePriceCommand(Sku::fromTrusted('UNKNOWN-SKU'), price: Money::inclusive(30.00)),
        ]);

        self::assertSame(1, $result->total);
        self::assertSame(0, $result->succeeded);
        self::assertCount(1, $result->permanentFailures);
        self::assertSame('UNKNOWN-SKU', $result->permanentFailures[0]->sku?->value);
        self::assertStringContainsString('does not belong to product', $result->permanentFailures[0]->error);

        $this->priceClient->shouldNotHaveBeenCalled();
    }

    // ========================================================================
    // Pre-flight: Invalid Price Relationship
    // ========================================================================

    #[Test]
    public function sale_price_exceeding_base_after_carry_forward_is_permanent_failure(): void
    {
        // Product has base=10, no sale
        $product = self::createProduct('MASTER-001', 10.00, null);
        $this->productRepo->shouldReceive('getProductByAnySku')->once()->andReturn($product);

        // Commanding sale=15 (without changing base=10) → sale >= base
        $result = $this->useCase->execute([
            new UpdatePriceCommand(Sku::fromTrusted('MASTER-001'), salePrice: Money::inclusive(15.00)),
        ]);

        self::assertSame(1, $result->total);
        self::assertSame(0, $result->succeeded);
        self::assertCount(1, $result->permanentFailures);
        self::assertStringContainsString('must be less than', $result->permanentFailures[0]->error);

        $this->priceClient->shouldNotHaveBeenCalled();
    }

    // ========================================================================
    // API: Item Rejected (updated: false)
    // ========================================================================

    #[Test]
    public function api_updated_false_is_permanent_failure(): void
    {
        $product = self::createProduct('MASTER-001', 20.00, null);
        $this->productRepo->shouldReceive('getProductByAnySku')->once()->andReturn($product);

        $this->priceClient->shouldReceive('updatePrices')
            ->once()
            ->andReturn(new PriceUpdateClientResult(
                results: [
                    new PriceUpdateItemResult(Sku::fromTrusted('MASTER-001'), updated: false),
                ],
            ));

        $result = $this->useCase->execute([
            new UpdatePriceCommand(Sku::fromTrusted('MASTER-001'), price: Money::inclusive(30.00)),
        ]);

        self::assertSame(0, $result->succeeded);
        self::assertCount(1, $result->permanentFailures);
        self::assertStringContainsString('not updated', $result->permanentFailures[0]->error);

        Event::assertNotDispatched(SkuRetailPricingUpdatedEvent::class);
        Event::assertNotDispatched(ProductPricingUpdatedEvent::class);
    }

    // ========================================================================
    // API: Transport Failure Classification
    // ========================================================================

    #[Test]
    public function transient_transport_failure_classified_as_temporary(): void
    {
        $product = self::createProduct('MASTER-001', 20.00, null);
        $this->productRepo->shouldReceive('getProductByAnySku')->once()->andReturn($product);

        $this->priceClient->shouldReceive('updatePrices')
            ->once()
            ->andReturn(new PriceUpdateClientResult(
                results: [],
                transportFailures: [
                    new ExternalServiceUnavailableException('Shopwired', 60),
                ],
            ));

        $result = $this->useCase->execute([
            new UpdatePriceCommand(Sku::fromTrusted('MASTER-001'), price: Money::inclusive(30.00)),
        ]);

        self::assertSame(0, $result->succeeded);
        self::assertCount(1, $result->temporaryFailures);
        self::assertNull($result->temporaryFailures[0]->sku);
    }

    // ========================================================================
    // Mixed Scenario
    // ========================================================================

    #[Test]
    public function mixed_scenario_with_skipped_validated_and_api_result(): void
    {
        $product = self::createProduct('MASTER-001', 20.00, null, [
            self::createVariation(1, 'VAR-001', 25.00, null),
            self::createVariation(2, 'VAR-002', 30.00, null),
        ]);
        $this->productRepo->shouldReceive('getProductByAnySku')->once()->andReturn($product);

        $this->priceClient->shouldReceive('updatePrices')
            ->once()
            ->andReturn(new PriceUpdateClientResult(
                results: [
                    new PriceUpdateItemResult(Sku::fromTrusted('VAR-001'), updated: true, productId: IntId::fromTrusted(1)),
                ],
            ));

        $result = $this->useCase->execute([
            // MASTER-001: same price → skipped
            new UpdatePriceCommand(Sku::fromTrusted('MASTER-001'), price: Money::inclusive(20.00)),
            // VAR-001: price change → validated, API confirms
            new UpdatePriceCommand(Sku::fromTrusted('VAR-001'), price: Money::inclusive(35.00)),
            // VAR-UNKNOWN: ownership failure
            new UpdatePriceCommand(Sku::fromTrusted('VAR-UNKNOWN'), price: Money::inclusive(15.00)),
        ]);

        self::assertSame(3, $result->total);
        self::assertSame(1, $result->succeeded);
        self::assertCount(1, $result->skipped);
        self::assertCount(1, $result->permanentFailures);

        Event::assertDispatched(SkuRetailPricingUpdatedEvent::class, 1);
        Event::assertDispatched(ProductPricingUpdatedEvent::class, 1);

        // Verify the product event carries the correct price change
        Event::assertDispatched(ProductPricingUpdatedEvent::class, static fn(ProductPricingUpdatedEvent $event): bool => $event->productId->value === 1
                && \count($event->priceChanges) === 1
                && $event->priceChanges[0]->sku->value === 'VAR-001');
    }

    // ========================================================================
    // Event Dispatch Conditions
    // ========================================================================

    #[Test]
    public function no_events_when_no_skus_confirmed_updated(): void
    {
        $product = self::createProduct('MASTER-001', 20.00, null);
        $this->productRepo->shouldReceive('getProductByAnySku')->once()->andReturn($product);

        $this->priceClient->shouldReceive('updatePrices')
            ->once()
            ->andReturn(new PriceUpdateClientResult(
                results: [
                    new PriceUpdateItemResult(Sku::fromTrusted('MASTER-001'), updated: false),
                ],
            ));

        $this->useCase->execute([
            new UpdatePriceCommand(Sku::fromTrusted('MASTER-001'), price: Money::inclusive(30.00)),
        ]);

        Event::assertNotDispatched(SkuRetailPricingUpdatedEvent::class);
        Event::assertNotDispatched(ProductPricingUpdatedEvent::class);
    }

    // ========================================================================
    // Sale Price Clearing (salePrice: 0)
    // ========================================================================

    #[Test]
    public function clearing_sale_price_with_zero_is_detected_as_change(): void
    {
        $product = self::createProduct('MASTER-001', 20.00, 15.00);
        $this->productRepo->shouldReceive('getProductByAnySku')->once()->andReturn($product);

        $this->priceClient->shouldReceive('updatePrices')
            ->once()
            ->andReturn(new PriceUpdateClientResult(
                results: [
                    new PriceUpdateItemResult(Sku::fromTrusted('MASTER-001'), updated: true, productId: IntId::fromTrusted(1)),
                ],
            ));

        $result = $this->useCase->execute([
            new UpdatePriceCommand(Sku::fromTrusted('MASTER-001'), salePrice: Money::inclusive(0.00)),
        ]);

        self::assertSame(1, $result->succeeded);
        self::assertSame([], $result->skipped);

        Event::assertDispatched(SkuRetailPricingUpdatedEvent::class, static function (SkuRetailPricingUpdatedEvent $event): bool {
            // Previous had active sale, new should have no sale
            return $event->previousPrices->saleActive()
                && ! $event->newPrices->saleActive();
        });
    }

    // ========================================================================
    // Factory Helpers
    // ========================================================================

    /**
     * @param list<ProductVariation> $variations
     */
    private static function createProduct(
        string $masterSku,
        float $price,
        ?float $salePrice,
        array $variations = [],
    ): Product {
        return new Product(
            id: 1,
            sku: $masterSku,
            gtin: null,
            title: 'Test Product',
            description: null,
            slug: 'test-product',
            url: 'https://example.com/test',
            price: $price,
            costPrice: null,
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
            variations: $variations,
            images: [],
            rawCustomFields: [],
            customFields: [],
            rawFilters: [],
            filters: [],
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
        );
    }

    private static function createVariation(
        int $id,
        string $sku,
        ?float $price,
        ?float $salePrice,
    ): ProductVariation {
        return new ProductVariation(
            id: $id,
            productExternalId: 1,
            sku: $sku,
            price: $price,
            costPrice: null,
            salePrice: $salePrice,
            stock: 50,
            weight: null,
            gtin: null,
            mpn: null,
            imageIndex: null,
        );
    }
}
