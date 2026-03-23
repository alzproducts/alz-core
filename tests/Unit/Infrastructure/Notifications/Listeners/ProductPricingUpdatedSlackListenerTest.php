<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notifications\Listeners;

use App\Application\Contracts\ChatNotificationInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\Product\Events\ProductPricingUpdatedEvent;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
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

    private ProductPricingUpdatedSlackListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chat = Mockery::mock(ChatNotificationInterface::class);
        $this->productRepo = Mockery::mock(ProductRepositoryInterface::class);
        $this->listener = new ProductPricingUpdatedSlackListener($this->chat, $this->productRepo);
    }

    #[Test]
    public function happy_path_enriches_and_sends_notification(): void
    {
        $event = self::createEvent();
        $product = self::createProduct();

        $this->productRepo->shouldReceive('getProduct')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 42))
            ->andReturn($product);

        $this->chat->shouldReceive('sendPriceUpdateAlert')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 42),
                Mockery::type('array'),
                'Test Product',
                'https://example.com/test',
                null,
            );

        $this->listener->handle($event);
    }

    #[Test]
    public function sends_notification_with_null_enrichment_when_product_lookup_fails(): void
    {
        $event = self::createEvent();

        $this->productRepo->shouldReceive('getProduct')
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
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 42),
                Mockery::type('array'),
                null,
                null,
                null,
            );

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

    private static function createProduct(): Product
    {
        return new Product(
            id: 42,
            sku: 'TEST-001',
            gtin: null,
            title: 'Test Product',
            description: null,
            slug: 'test-product',
            url: 'https://example.com/test',
            price: 25.00,
            costPrice: null,
            salePrice: null,
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
            customFields: [],
            rawFilters: [],
            filters: [],
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
        );
    }
}
