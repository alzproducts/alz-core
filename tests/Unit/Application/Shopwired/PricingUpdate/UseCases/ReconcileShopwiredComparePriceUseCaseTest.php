<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\PricingUpdate\UseCases;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Application\Shopwired\PricingUpdate\UseCases\ReconcileShopwiredComparePriceUseCase;
use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\ValueObjects\ProductLinks;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Catalog\Product\ValueObjects\ProductViewMeta;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Drives the uniform-RRP business rule: the comparePrice pushed to ShopWired
 * must equal the single RRP shared by every sellable SKU, or null when any
 * SKU is missing an RRP or the RRPs disagree.
 */
#[CoversClass(ReconcileShopwiredComparePriceUseCase::class)]
final class ReconcileShopwiredComparePriceUseCaseTest extends TestCase
{
    private ProductRepositoryInterface&MockInterface $productRepo;

    private ProductUpdateClientInterface&MockInterface $productUpdateClient;

    private ReconcileShopwiredComparePriceUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->productRepo = Mockery::mock(ProductRepositoryInterface::class);
        $this->productUpdateClient = Mockery::mock(ProductUpdateClientInterface::class);
        $logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new ReconcileShopwiredComparePriceUseCase(
            productRepo: $this->productRepo,
            productUpdateClient: $this->productUpdateClient,
            logger: $logger,
        );
    }

    #[Test]
    public function pushes_uniform_rrp_as_compare_price_when_all_sellable_skus_agree(): void
    {
        $view = $this->createView(
            sku: 'PARENT',
            rrp: 20.00,
            variations: [
                $this->createVariation(sku: 'V-1', rrp: 20.00),
                $this->createVariation(sku: 'V-2', rrp: 20.00),
            ],
        );

        $this->productRepo->shouldReceive('findDetailedProductView')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 42), [ProductInclude::Variations])
            ->andReturn($view);

        $this->productUpdateClient->shouldReceive('updateComparePrice')
            ->once()
            ->with(42, 20.00);

        $this->useCase->execute(IntId::from(42));
    }

    #[Test]
    public function clears_compare_price_when_variations_have_different_rrps(): void
    {
        $view = $this->createView(
            sku: null,
            variations: [
                $this->createVariation(sku: 'V-1', rrp: 20.00),
                $this->createVariation(sku: 'V-2', rrp: 25.00),
            ],
        );

        $this->productRepo->shouldReceive('findDetailedProductView')
            ->once()
            ->andReturn($view);

        $this->productUpdateClient->shouldReceive('updateComparePrice')
            ->once()
            ->with(42, null);

        $this->useCase->execute(IntId::from(42));
    }

    #[Test]
    public function pushes_uniform_rrp_even_when_selling_prices_differ(): void
    {
        // Behaviour change from the previous rule: selling-price uniformity
        // is no longer the gate. What matters is RRP uniformity.
        $view = $this->createView(
            sku: null,
            variations: [
                $this->createVariation(sku: 'V-1', price: 10.00, rrp: 15.00),
                $this->createVariation(sku: 'V-2', price: 12.00, rrp: 15.00),
            ],
        );

        $this->productRepo->shouldReceive('findDetailedProductView')
            ->once()
            ->andReturn($view);

        $this->productUpdateClient->shouldReceive('updateComparePrice')
            ->once()
            ->with(42, 15.00);

        $this->useCase->execute(IntId::from(42));
    }

    #[Test]
    public function clears_compare_price_when_any_sellable_sku_has_no_rrp(): void
    {
        $view = $this->createView(
            sku: null,
            variations: [
                $this->createVariation(sku: 'V-1', rrp: 20.00),
                $this->createVariation(sku: 'V-2', rrp: null),
            ],
        );

        $this->productRepo->shouldReceive('findDetailedProductView')
            ->once()
            ->andReturn($view);

        $this->productUpdateClient->shouldReceive('updateComparePrice')
            ->once()
            ->with(42, null);

        $this->useCase->execute(IntId::from(42));
    }

    #[Test]
    public function pushes_parent_rrp_for_simple_product_without_variations(): void
    {
        $view = $this->createView(
            sku: 'SIMPLE',
            rrp: 15.00,
            variations: [],
        );

        $this->productRepo->shouldReceive('findDetailedProductView')
            ->once()
            ->andReturn($view);

        $this->productUpdateClient->shouldReceive('updateComparePrice')
            ->once()
            ->with(42, 15.00);

        $this->useCase->execute(IntId::from(42));
    }

    #[Test]
    public function clears_compare_price_for_simple_product_without_rrp(): void
    {
        $view = $this->createView(
            sku: 'SIMPLE',
            rrp: null,
            variations: [],
        );

        $this->productRepo->shouldReceive('findDetailedProductView')
            ->once()
            ->andReturn($view);

        $this->productUpdateClient->shouldReceive('updateComparePrice')
            ->once()
            ->with(42, null);

        $this->useCase->execute(IntId::from(42));
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createVariation(
        ?string $sku,
        ?float $rrp = null,
        float $price = 50.00,
    ): ProductVariationView {
        static $id = 100;

        return new ProductVariationView(
            externalId: $id++,
            sku: $sku,
            gtin: null,
            price: $price,
            costPrice: null,
            salePrice: null,
            rrp: $rrp,
            effectivePrice: $price,
            isOnSale: false,
            profitMargin: null,
            availableStock: 5,
            physicalStock: 5,
            weight: null,
            vatExclusive: false,
            mpn: null,
            imageIndex: null,
            options: [],
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
        );
    }

    /**
     * @param list<ProductVariationView> $variations
     */
    private function createView(
        ?string $sku,
        ?float $rrp = null,
        array $variations = [],
    ): ProductView {
        return new ProductView(
            externalId: 42,
            sku: $sku,
            gtin: null,
            title: 'Test Product',
            description: null,
            slug: 'test-product',
            links: new ProductLinks(
                publicUrl: 'https://example.com/test-product',
                editWebsiteUrl: 'https://admin.myshopwired.uk/business/manage-ecommerce-add-product/42',
            ),
            price: 10.00,
            costPrice: null,
            salePrice: null,
            rrp: $rrp,
            effectivePrice: 10.00,
            isOnSale: false,
            profitMargin: null,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [],
            variations: $variations,
            images: [],
            customFields: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
            meta: new ProductViewMeta($variations, null, null),
            hasAnyVariationOnSale: ProductVariationView::anyOnSale($variations),
            parentAvailableStock: 0,
            parentPhysicalStock: 0,
            allVariations: $variations,
            freeDelivery: FreeDeliveryType::None,
        );
    }
}
