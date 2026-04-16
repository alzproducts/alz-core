<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\Queries\CategoryListQueryParams;
use App\Application\Catalog\UseCases\ListCategoriesUseCase;
use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Application\DTOs\PaginatedListDTO;
use App\Domain\Catalog\Category\Enums\CategoryInclude;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(ListCategoriesUseCase::class)]
final class ListCategoriesUseCaseTest extends TestCase
{
    private CategoryRepositoryInterface&MockInterface $categoryRepository;

    private LoggerInterface&MockInterface $logger;

    private ListCategoriesUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoryRepository = Mockery::mock(CategoryRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new ListCategoriesUseCase(
            $this->categoryRepository,
            $this->logger,
        );
    }

    #[Test]
    public function execute_delegates_to_repository_and_returns_paginated_dto(): void
    {
        $expected = PaginatedListDTO::fromPage(items: [], total: 0, perPage: 10, currentPage: 1);

        $params = new CategoryListQueryParams();

        $this->categoryRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(10, 1, $params)
            ->andReturn($expected);

        $this->logger->shouldReceive('info')->once()->with('Listing categories', ['page' => 1, 'per_page' => 10, 'includes' => [], 'include_inactive' => false, 'is_main_category' => null]);
        $this->logger->shouldReceive('info')->once()->with('Listed categories', ['total' => 0, 'returned' => 0]);

        $result = $this->useCase->execute(perPage: 10, page: 1, params: $params);

        $this->assertSame($expected, $result);
    }

    #[Test]
    public function execute_passes_includes_and_include_inactive_through_to_repository(): void
    {
        $includes = [CategoryInclude::CustomFields];
        $expected = PaginatedListDTO::fromPage(items: [], total: 5, perPage: 20, currentPage: 2);
        $params = new CategoryListQueryParams(includes: $includes, includeInactive: true);

        $this->categoryRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(20, 2, $params)
            ->andReturn($expected);

        $this->logger->shouldReceive('info')->once()->with('Listing categories', ['page' => 2, 'per_page' => 20, 'includes' => ['custom_fields'], 'include_inactive' => true, 'is_main_category' => null]);
        $this->logger->shouldReceive('info')->once()->with('Listed categories', ['total' => 5, 'returned' => 0]);

        $result = $this->useCase->execute(perPage: 20, page: 2, params: $params);

        $this->assertSame($expected, $result);
    }

    #[Test]
    public function execute_logs_listing_and_listed_messages_with_correct_context(): void
    {
        $items = ['item1', 'item2', 'item3'];
        $dto = PaginatedListDTO::fromPage(items: $items, total: 100, perPage: 10, currentPage: 1);

        $this->categoryRepository
            ->shouldReceive('paginate')
            ->once()
            ->andReturn($dto);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Listing categories', ['page' => 1, 'per_page' => 10, 'includes' => [], 'include_inactive' => false, 'is_main_category' => null]);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Listed categories', ['total' => 100, 'returned' => 3]);

        $this->useCase->execute(perPage: 10, page: 1);
    }
}
