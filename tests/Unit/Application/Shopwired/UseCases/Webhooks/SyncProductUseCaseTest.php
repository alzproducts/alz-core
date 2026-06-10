<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Shopwired\DTOs\WebhookContextDTO;
use App\Application\Shopwired\Enums\WebhookTopic;
use App\Application\Shopwired\UseCases\Webhooks\AbstractSyncEntityWebhookUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncProductUseCase;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldValueList;
use App\Domain\Catalog\Product\ValueObjects\Product;
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
 * SyncProductUseCase Unit Tests.
 *
 * Tests staleness guard, idempotency guard, and presentEmbeds threading.
 */
#[CoversClass(SyncProductUseCase::class)]
#[CoversClass(AbstractSyncEntityWebhookUseCase::class)]
final class SyncProductUseCaseTest extends TestCase
{
    private ProductRepositoryInterface&MockInterface $repository;

    private ShopwiredSyncDispatcherInterface&MockInterface $dispatcher;

    private WebhookIdempotencyServiceInterface&MockInterface $idempotency;

    private LoggerInterface&MockInterface $logger;

    private SyncProductUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(ProductRepositoryInterface::class);
        $this->dispatcher = Mockery::mock(ShopwiredSyncDispatcherInterface::class);
        $this->idempotency = Mockery::mock(WebhookIdempotencyServiceInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new SyncProductUseCase(
            productRepository: $this->repository,
            dispatcher: $this->dispatcher,
            idempotency: $this->idempotency,
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

        $this->idempotency->shouldReceive('isSuperseded')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 12345), WebhookTopic::ProductUpdated, 1)
            ->andReturnFalse();

        $this->repository->shouldReceive('saveFromWebhook')
            ->once()
            ->with($product, $presentEmbeds);

        $this->idempotency->shouldReceive('record')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 12345), WebhookTopic::ProductUpdated, 1, $eventTime);

        $this->dispatcher->shouldReceive('dispatchProductSync')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 12345));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing product webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Product webhook processed — sync queued', Mockery::type('array'));

        $this->useCase->execute(
            context: new WebhookContextDTO($eventTime, 1, WebhookTopic::ProductUpdated),
            product: $product,
            presentEmbeds: $presentEmbeds,
        );
    }

    #[Test]
    public function it_defaults_to_empty_present_embeds(): void
    {
        $product = self::buildProduct();
        $eventTime = new DateTimeImmutable('now');

        $this->idempotency->shouldReceive('isSuperseded')
            ->once()
            ->andReturnFalse();

        $this->repository->shouldReceive('saveFromWebhook')
            ->once()
            ->with($product, []);

        $this->idempotency->shouldReceive('record')
            ->once();

        $this->dispatcher->shouldReceive('dispatchProductSync')
            ->once();

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing product webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Product webhook processed — sync queued', Mockery::type('array'));

        $this->useCase->execute(
            context: new WebhookContextDTO($eventTime, 1, WebhookTopic::ProductUpdated),
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

        $this->idempotency->shouldNotReceive('isSuperseded');
        $this->repository->shouldNotReceive('saveFromWebhook');
        $this->idempotency->shouldNotReceive('record');
        $this->dispatcher->shouldNotReceive('dispatchProductSync');

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing product webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Discarding stale product webhook', Mockery::type('array'));

        $this->useCase->execute(
            context: new WebhookContextDTO($staleTime, 1, WebhookTopic::ProductUpdated),
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

        $this->idempotency->shouldReceive('isSuperseded')
            ->once()
            ->andReturnTrue();

        $this->repository->shouldNotReceive('saveFromWebhook');
        $this->idempotency->shouldNotReceive('record');
        $this->dispatcher->shouldNotReceive('dispatchProductSync');

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing product webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Discarding already-processed product webhook', Mockery::type('array'));

        $this->useCase->execute(
            context: new WebhookContextDTO($eventTime, 1, WebhookTopic::ProductUpdated),
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
            customFields: CustomFieldValueList::empty(),
            rawFilters: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2025-01-01'),
            updatedAt: new DateTimeImmutable('2025-06-15'),
        );
    }
}
