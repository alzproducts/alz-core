<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Jobs\Shopwired\SyncShopwiredBrandJob;
use App\Application\Shopwired\Enums\WebhookTopic;
use App\Application\Shopwired\UseCases\Webhooks\SyncBrandUseCase;
use App\Domain\Catalog\ValueObjects\Brand;
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

#[CoversClass(SyncBrandUseCase::class)]
final class SyncBrandUseCaseTest extends TestCase
{
    private BrandRepositoryInterface&MockInterface $repository;

    private WebhookIdempotencyServiceInterface&MockInterface $idempotency;

    private LoggerInterface&MockInterface $logger;

    private SyncBrandUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SyncShopwiredBrandJob::class]);

        $this->repository = Mockery::mock(BrandRepositoryInterface::class);
        $this->idempotency = Mockery::mock(WebhookIdempotencyServiceInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new SyncBrandUseCase(
            brandRepository: $this->repository,
            idempotency: $this->idempotency,
            logger: $this->logger,
            webhookStalenessHours: 24,
        );
    }

    // ========================================================================
    // Happy Path
    // ========================================================================

    #[Test]
    public function it_saves_brand_and_queues_sync_with_present_embeds(): void
    {
        $brand = self::buildBrand();
        $eventTime = new DateTimeImmutable('now');
        $presentEmbeds = ['custom_fields'];

        $this->idempotency->shouldReceive('isSuperseded')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 7), WebhookTopic::BrandUpdated, 1)
            ->andReturnFalse();

        $this->repository->shouldReceive('saveFromWebhook')
            ->once()
            ->with($brand, $presentEmbeds);

        $this->idempotency->shouldReceive('record')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 7), WebhookTopic::BrandUpdated, 1, $eventTime);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing brand webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Brand webhook processed — sync queued', Mockery::type('array'));

        $this->useCase->execute(
            eventTime: $eventTime,
            webhookId: 1,
            topic: WebhookTopic::BrandUpdated,
            brand: $brand,
            presentEmbeds: $presentEmbeds,
        );
    }

    #[Test]
    public function it_defaults_to_empty_present_embeds(): void
    {
        $brand = self::buildBrand();
        $eventTime = new DateTimeImmutable('now');

        $this->idempotency->shouldReceive('isSuperseded')
            ->once()
            ->andReturnFalse();

        $this->repository->shouldReceive('saveFromWebhook')
            ->once()
            ->with($brand, []);

        $this->idempotency->shouldReceive('record')
            ->once();

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing brand webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Brand webhook processed — sync queued', Mockery::type('array'));

        $this->useCase->execute(
            eventTime: $eventTime,
            webhookId: 1,
            topic: WebhookTopic::BrandUpdated,
            brand: $brand,
        );
    }

    // ========================================================================
    // Staleness Guard
    // ========================================================================

    #[Test]
    public function it_discards_stale_webhook(): void
    {
        $brand = self::buildBrand();
        $staleTime = new DateTimeImmutable('-48 hours');

        $this->idempotency->shouldNotReceive('isSuperseded');
        $this->repository->shouldNotReceive('saveFromWebhook');
        $this->idempotency->shouldNotReceive('record');

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing brand webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Discarding stale brand webhook', Mockery::type('array'));

        $this->useCase->execute(
            eventTime: $staleTime,
            webhookId: 1,
            topic: WebhookTopic::BrandUpdated,
            brand: $brand,
        );
    }

    // ========================================================================
    // Idempotency Guard
    // ========================================================================

    #[Test]
    public function it_discards_already_processed_webhook(): void
    {
        $brand = self::buildBrand();
        $eventTime = new DateTimeImmutable('now');

        $this->idempotency->shouldReceive('isSuperseded')
            ->once()
            ->andReturnTrue();

        $this->repository->shouldNotReceive('saveFromWebhook');
        $this->idempotency->shouldNotReceive('record');

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing brand webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Discarding already-processed brand webhook', Mockery::type('array'));

        $this->useCase->execute(
            eventTime: $eventTime,
            webhookId: 1,
            topic: WebhookTopic::BrandUpdated,
            brand: $brand,
        );
    }

    // ========================================================================
    // Fixtures
    // ========================================================================

    private static function buildBrand(): Brand
    {
        return new Brand(
            id: 7,
            createdAt: new DateTimeImmutable('2025-01-01'),
            title: 'Test Brand',
            description: null,
            slug: 'test-brand',
            url: '/brands/test-brand',
            active: true,
            featured: false,
            sortOrder: 1,
            metaTitle: null,
            metaKeywords: null,
            metaDescription: null,
        );
    }
}
