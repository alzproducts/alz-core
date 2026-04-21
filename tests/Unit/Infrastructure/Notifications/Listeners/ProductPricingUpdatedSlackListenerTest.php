<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notifications\Listeners;

use App\Application\Contracts\ChatNotificationInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\SaleSettingsRepositoryInterface;
use App\Application\Notifications\DTOs\PriceUpdateAlertDataDTO;
use App\Domain\Catalog\Product\Events\ProductPricingUpdatedEvent;
use App\Domain\Catalog\Product\ValueObjects\ProductLinks;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Catalog\Product\ValueObjects\ProductViewMeta;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Catalog\Product\ValueObjects\SkuPriceChange;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Notifications\Listeners\ProductPricingUpdatedSlackListener;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(ProductPricingUpdatedSlackListener::class)]
final class ProductPricingUpdatedSlackListenerTest extends TestCase
{
    private ChatNotificationInterface&MockInterface $chat;

    private ProductRepositoryInterface&MockInterface $productRepo;

    private SaleSettingsRepositoryInterface&MockInterface $saleSettingsRepo;

    private ProductPricingUpdatedSlackListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chat = Mockery::mock(ChatNotificationInterface::class);
        $this->productRepo = Mockery::mock(ProductRepositoryInterface::class);
        $this->saleSettingsRepo = Mockery::mock(SaleSettingsRepositoryInterface::class)->shouldIgnoreMissing();

        $this->listener = new ProductPricingUpdatedSlackListener(
            $this->chat,
            $this->productRepo,
            $this->saleSettingsRepo,
        );
    }

    #[Test]
    public function happy_path_enriches_and_sends_notification(): void
    {
        $event = self::createEvent();
        $view = self::createProductView();

        $this->productRepo->shouldReceive('findDetailedProductView')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 42))
            ->andReturn($view);

        $this->chat->shouldReceive('sendPriceUpdateAlert')
            ->once()
            ->with(Mockery::on(
                static fn(PriceUpdateAlertDataDTO $data): bool => $data->productId->value === 42
                && $data->productTitle === 'Test Product'
                && $data->productUrl === 'https://example.com/test',
            ));

        $this->listener->handle($event);
    }

    #[Test]
    public function sends_notification_with_null_enrichment_when_product_lookup_fails(): void
    {
        $event = self::createEvent();

        $this->productRepo->shouldReceive('findDetailedProductView')
            ->once()
            ->andThrow(new ResourceNotFoundException('Shopwired', 'product', '42'));

        Log::shouldReceive('warning')
            ->once()
            ->with(
                'Could not enrich pricing notification with product details',
                Mockery::on(static fn(array $ctx): bool => $ctx['product_id'] === 42),
            );

        $this->chat->shouldReceive('sendPriceUpdateAlert')
            ->once()
            ->with(Mockery::on(
                static fn(PriceUpdateAlertDataDTO $data): bool => $data->productId->value === 42
                && $data->productTitle === null
                && $data->productUrl === null,
            ));

        $this->listener->handle($event);
    }

    #[Test]
    public function failed_method_logs_error(): void
    {
        $event = self::createEvent();
        $exception = new RuntimeException('Slack unavailable');

        Log::shouldReceive('error')
            ->once()
            ->with(
                'Could not send product pricing update notification',
                Mockery::on(static fn(array $ctx): bool => $ctx['product_id'] === 42),
            );

        $this->listener->failed($event, $exception);
    }

    #[Test]
    public function queue_configuration_is_correct(): void
    {
        self::assertSame(3, $this->listener->tries);
        self::assertSame(60, $this->listener->backoff);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private static function createEvent(): ProductPricingUpdatedEvent
    {
        return new ProductPricingUpdatedEvent(
            productId: IntId::fromTrusted(42),
            priceChanges: [
                new SkuPriceChange(
                    sku: Sku::fromTrusted('TEST-001'),
                    previousPrices: new ProductRetailPricing(basePrice: Money::inclusive(20.00)),
                    newPrices: new ProductRetailPricing(basePrice: Money::inclusive(25.00)),
                ),
            ],
        );
    }

    private static function createProductView(): ProductView
    {
        return new ProductView(
            externalId: 42,
            sku: 'TEST-001',
            gtin: null,
            title: 'Test Product',
            description: null,
            slug: 'test-product',
            links: new ProductLinks(
                publicUrl: 'https://example.com/test',
                editWebsiteUrl: 'https://admin.myshopwired.uk/business/manage-ecommerce-add-product/42',
            ),
            price: 25.00,
            costPrice: null,
            salePrice: null,
            rrp: null,
            effectivePrice: 25.00,
            isOnSale: false,
            profitMargin: null,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [],
            variations: [],
            images: [],
            customFields: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
            meta: new ProductViewMeta([], null, null),
            hasAnyVariationOnSale: ProductVariationView::anyOnSale([]),
            parentAvailableStock: 100,
            parentPhysicalStock: 100,
            allVariations: [],
        );
    }
}
