<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Jobs\Shopwired\SyncShopwiredProductJob;
use App\Application\Shopwired\UseCases\Webhooks\SyncProductUseCase;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * SyncProductUseCase Unit Tests.
 *
 * Tests staleness guard, idempotency guard, and presentEmbeds threading.
 */
#[CoversClass(SyncProductUseCase::class)]
final class SyncProductUseCaseTest extends TestCase
{
    private ProductRepositoryInterface&MockInterface $repository;

    private LoggerInterface&MockInterface $logger;

    private SyncProductUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SyncShopwiredProductJob::class]);

        $this->repository = Mockery::mock(ProductRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new SyncProductUseCase(
            productRepository: $this->repository,
            logger: $this->logger,
            webhookStalenessHours: 24,
        );
    }

    // ========================================================================
    // Happy Path
    // ========================================================================

    #[Test]
    public function it_saves_product_and_queues_sync_with_present_embeds(): void
    {
        $product = self::buildProduct();
        $eventTime = new DateTimeImmutable('now');
        $presentEmbeds = ['vat_relief', 'categories'];

        $this->repository->shouldReceive('getWebhookTimestamp')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 12345))
            ->andReturnNull();

        $this->repository->shouldReceive('saveFromWebhook')
            ->once()
            ->with($product, $eventTime, $presentEmbeds);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Product webhook processed — sync queued', Mockery::type('array'));

        $this->useCase->execute(
            eventTime: $eventTime,
            webhookId: 1,
            product: $product,
            presentEmbeds: $presentEmbeds,
        );
    }

    #[Test]
    public function it_defaults_to_empty_present_embeds(): void
    {
        $product = self::buildProduct();
        $eventTime = new DateTimeImmutable('now');

        $this->repository->shouldReceive('getWebhookTimestamp')
            ->once()
            ->andReturnNull();

        $this->repository->shouldReceive('saveFromWebhook')
            ->once()
            ->with($product, $eventTime, []);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Product webhook processed — sync queued', Mockery::type('array'));

        $this->useCase->execute(
            eventTime: $eventTime,
            webhookId: 1,
            product: $product,
        );
    }

    // ========================================================================
    // Staleness Guard
    // ========================================================================

    #[Test]
    public function it_discards_stale_webhook(): void
    {
        $product = self::buildProduct();
        $staleTime = new DateTimeImmutable('-48 hours');

        $this->repository->shouldNotReceive('getWebhookTimestamp');
        $this->repository->shouldNotReceive('saveFromWebhook');

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Discarding stale product webhook', Mockery::type('array'));

        $this->useCase->execute(
            eventTime: $staleTime,
            webhookId: 1,
            product: $product,
            presentEmbeds: ['vat_relief'],
        );
    }

    // ========================================================================
    // Idempotency Guard
    // ========================================================================

    #[Test]
    public function it_discards_already_processed_webhook(): void
    {
        $product = self::buildProduct();
        $eventTime = new DateTimeImmutable('now');
        $existingTimestamp = new DateTimeImmutable('+1 hour');

        $this->repository->shouldReceive('getWebhookTimestamp')
            ->once()
            ->andReturn($existingTimestamp);

        $this->repository->shouldNotReceive('saveFromWebhook');

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Discarding already-processed product webhook', Mockery::type('array'));

        $this->useCase->execute(
            eventTime: $eventTime,
            webhookId: 1,
            product: $product,
        );
    }

    // ========================================================================
    // Fixtures
    // ========================================================================

    private static function buildProduct(): Product
    {
        return new Product(
            id: 12345,
            sku: 'TEST-SKU',
            gtin: null,
            title: 'Test Product',
            description: null,
            slug: 'test-product',
            url: 'https://shop.example.com/test-product',
            price: 29.99,
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
            createdAt: new DateTimeImmutable('2025-01-01'),
            updatedAt: new DateTimeImmutable('2025-06-15'),
        );
    }
}
