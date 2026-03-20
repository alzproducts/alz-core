<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Shopwired\Enums\WebhookTopic;
use App\Application\Shopwired\UseCases\Webhooks\AbstractSyncEntityWebhookUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncCategoryUseCase;
use App\Domain\Catalog\ValueObjects\Category;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(SyncCategoryUseCase::class)]
#[CoversClass(AbstractSyncEntityWebhookUseCase::class)]
final class SyncCategoryUseCaseTest extends TestCase
{
    private CategoryRepositoryInterface&MockInterface $repository;

    private ShopwiredSyncDispatcherInterface&MockInterface $dispatcher;

    private WebhookIdempotencyServiceInterface&MockInterface $idempotency;

    private LoggerInterface&MockInterface $logger;

    private SyncCategoryUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(CategoryRepositoryInterface::class);
        $this->dispatcher = Mockery::mock(ShopwiredSyncDispatcherInterface::class);
        $this->idempotency = Mockery::mock(WebhookIdempotencyServiceInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new SyncCategoryUseCase(
            categoryRepository: $this->repository,
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
    public function it_saves_category_and_queues_sync_with_present_embeds(): void
    {
        $category = self::buildCategory();
        $eventTime = new DateTimeImmutable('now');
        $presentEmbeds = ['parents', 'custom_fields'];

        $this->idempotency->shouldReceive('isSuperseded')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 42), WebhookTopic::CategoryUpdated, 1)
            ->andReturnFalse();

        $this->repository->shouldReceive('saveFromWebhook')
            ->once()
            ->with($category, $presentEmbeds);

        $this->idempotency->shouldReceive('record')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 42), WebhookTopic::CategoryUpdated, 1, $eventTime);

        $this->dispatcher->shouldReceive('dispatchCategorySync')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 42));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing category webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Category webhook processed — sync queued', Mockery::type('array'));

        $this->useCase->execute(
            eventTime: $eventTime,
            webhookId: 1,
            topic: WebhookTopic::CategoryUpdated,
            category: $category,
            presentEmbeds: $presentEmbeds,
        );
    }

    #[Test]
    public function it_defaults_to_empty_present_embeds(): void
    {
        $category = self::buildCategory();
        $eventTime = new DateTimeImmutable('now');

        $this->idempotency->shouldReceive('isSuperseded')
            ->once()
            ->andReturnFalse();

        $this->repository->shouldReceive('saveFromWebhook')
            ->once()
            ->with($category, []);

        $this->idempotency->shouldReceive('record')
            ->once();

        $this->dispatcher->shouldReceive('dispatchCategorySync')
            ->once();

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing category webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Category webhook processed — sync queued', Mockery::type('array'));

        $this->useCase->execute(
            eventTime: $eventTime,
            webhookId: 1,
            topic: WebhookTopic::CategoryUpdated,
            category: $category,
        );
    }

    // ========================================================================
    // Staleness Guard
    // ========================================================================

    #[Test]
    public function it_discards_stale_webhook(): void
    {
        $category = self::buildCategory();
        $staleTime = new DateTimeImmutable('-48 hours');

        $this->idempotency->shouldNotReceive('isSuperseded');
        $this->repository->shouldNotReceive('saveFromWebhook');
        $this->idempotency->shouldNotReceive('record');
        $this->dispatcher->shouldNotReceive('dispatchCategorySync');

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing category webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Discarding stale category webhook', Mockery::type('array'));

        $this->useCase->execute(
            eventTime: $staleTime,
            webhookId: 1,
            topic: WebhookTopic::CategoryUpdated,
            category: $category,
        );
    }

    // ========================================================================
    // Idempotency Guard
    // ========================================================================

    #[Test]
    public function it_discards_already_processed_webhook(): void
    {
        $category = self::buildCategory();
        $eventTime = new DateTimeImmutable('now');

        $this->idempotency->shouldReceive('isSuperseded')
            ->once()
            ->andReturnTrue();

        $this->repository->shouldNotReceive('saveFromWebhook');
        $this->idempotency->shouldNotReceive('record');
        $this->dispatcher->shouldNotReceive('dispatchCategorySync');

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing category webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Discarding already-processed category webhook', Mockery::type('array'));

        $this->useCase->execute(
            eventTime: $eventTime,
            webhookId: 1,
            topic: WebhookTopic::CategoryUpdated,
            category: $category,
        );
    }

    // ========================================================================
    // Fixtures
    // ========================================================================

    private static function buildCategory(): Category
    {
        return new Category(
            id: 42,
            createdAt: new DateTimeImmutable('2025-01-01'),
            title: 'Test Category',
            description: null,
            description2: null,
            slug: 'test-category',
            url: '/test-category',
            active: true,
            featured: false,
            tradeOnly: false,
            sortOrder: 1,
            metaTitle: null,
            metaDescription: null,
            metaKeywords: null,
            metaNoIndex: false,
        );
    }
}
