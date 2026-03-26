<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\UseCases\GetCategoryResult;
use App\Application\Catalog\UseCases\GetCategoryUseCase;
use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Domain\Catalog\Category\ValueObjects\CategoryView;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(GetCategoryUseCase::class)]
final class GetCategoryUseCaseTest extends TestCase
{
    private CategoryRepositoryInterface&MockInterface $categoryRepository;

    private LoggerInterface&MockInterface $logger;

    private GetCategoryUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoryRepository = Mockery::mock(CategoryRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new GetCategoryUseCase(
            $this->categoryRepository,
            $this->logger,
        );
    }

    #[Test]
    public function execute_delegates_to_repository_and_returns_get_category_result(): void
    {
        $category = self::buildCategoryView();

        $this->categoryRepository
            ->shouldReceive('findCategoryForApi')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 99), [])
            ->andReturn($category);

        $this->logger->shouldReceive('info')->once()->with('Getting category', ['category_id' => 99, 'includes' => []]);
        $this->logger->shouldReceive('info')->once()->with('Got category', ['category_id' => 99, 'title' => 'Test Category']);

        $result = $this->useCase->execute(categoryId: 99);

        self::assertInstanceOf(GetCategoryResult::class, $result);
        self::assertSame($category, $result->category);
        self::assertSame([], $result->includes);
    }

    #[Test]
    public function execute_passes_includes_through_to_repository(): void
    {
        $category = self::buildCategoryView();
        $includes = ['custom_fields', 'description'];

        $this->categoryRepository
            ->shouldReceive('findCategoryForApi')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 99), $includes)
            ->andReturn($category);

        $this->logger->shouldReceive('info')->once()->with('Getting category', ['category_id' => 99, 'includes' => $includes]);
        $this->logger->shouldReceive('info')->once()->with('Got category', ['category_id' => 99, 'title' => 'Test Category']);

        $result = $this->useCase->execute(categoryId: 99, includes: $includes);

        self::assertSame($includes, $result->includes);
    }

    #[Test]
    public function execute_logs_getting_and_got_messages_with_correct_context(): void
    {
        $category = self::buildCategoryView();

        $this->categoryRepository
            ->shouldReceive('findCategoryForApi')
            ->once()
            ->andReturn($category);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Getting category', ['category_id' => 99, 'includes' => []]);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Got category', ['category_id' => 99, 'title' => 'Test Category']);

        $this->useCase->execute(categoryId: 99);
    }

    private static function buildCategoryView(): CategoryView
    {
        return new CategoryView(
            id: IntId::from(99),
            title: 'Test Category',
            slug: 'test-category',
            url: '/categories/test-category',
            active: true,
            featured: false,
            sortOrder: 1,
            metaTitle: null,
            metaDescription: null,
            image: null,
            createdAt: new DateTimeImmutable('2025-01-01'),
        );
    }
}
