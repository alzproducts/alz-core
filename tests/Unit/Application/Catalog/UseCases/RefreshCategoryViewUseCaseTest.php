<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\UseCases\RefreshCategoryViewUseCase;
use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Domain\Catalog\Category\ValueObjects\Category;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(RefreshCategoryViewUseCase::class)]
final class RefreshCategoryViewUseCaseTest extends TestCase
{
    use MockeryPHPUnitIntegration;


    private CategoryClientInterface&MockInterface $client;

    private CategoryRepositoryInterface&MockInterface $repository;

    private LoggerInterface&MockInterface $logger;

    private RefreshCategoryViewUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(CategoryClientInterface::class);
        $this->repository = Mockery::mock(CategoryRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new RefreshCategoryViewUseCase(
            $this->client,
            $this->repository,
            $this->logger,
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function fetches_category_by_id_and_saves_the_result(): void
    {
        $category = $this->makeCategory(42);

        $this->client
            ->shouldReceive('getCategoryById')
            ->once()
            ->with(42)
            ->andReturn($category);

        $this->repository
            ->shouldReceive('save')
            ->once()
            ->with($category);

        $this->useCase->execute(IntId::from(42));
    }

    #[Test]
    public function logs_start_and_completion_with_category_id(): void
    {
        $category = $this->makeCategory(7);

        $this->client->shouldReceive('getCategoryById')->andReturn($category);
        $this->repository->shouldReceive('save');

        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Refreshing category', ['category_id' => 7]);
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Category refresh complete', ['category_id' => 7]);

        $useCase = new RefreshCategoryViewUseCase(
            $this->client,
            $this->repository,
            $this->logger,
        );

        $useCase->execute(IntId::from(7));
    }

    private function makeCategory(int $id): Category
    {
        return new Category(
            id: $id,
            createdAt: new DateTimeImmutable('2024-01-01'),
            title: 'Test Category',
            description: null,
            description2: null,
            slug: 'test-category',
            url: 'https://example.com/category/test-category',
            active: true,
            featured: false,
            tradeOnly: false,
            sortOrder: 0,
            metaTitle: null,
            metaDescription: null,
            metaKeywords: null,
            metaNoIndex: false,
        );
    }
}
