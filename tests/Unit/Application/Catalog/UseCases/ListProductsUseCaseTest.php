<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\UseCases\ListProductsUseCase;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\DTOs\PaginatedListDTO;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(ListProductsUseCase::class)]
final class ListProductsUseCaseTest extends TestCase
{
    private ProductRepositoryInterface&MockInterface $productRepository;

    private LoggerInterface&MockInterface $logger;

    private ListProductsUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new ListProductsUseCase(
            $this->productRepository,
            $this->logger,
        );
    }

    #[Test]
    public function execute_delegates_to_repository_and_returns_paginated_dto(): void
    {
        $expected = PaginatedListDTO::fromPage(items: [], total: 0, perPage: 10, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(10, 1, [])
            ->andReturn($expected);

        $this->logger->shouldReceive('info')->once()->with('Listing products', ['page' => 1, 'per_page' => 10, 'includes' => []]);
        $this->logger->shouldReceive('info')->once()->with('Listed products', ['total' => 0, 'returned' => 0]);

        $result = $this->useCase->execute(perPage: 10, page: 1);

        $this->assertSame($expected, $result);
    }

    #[Test]
    public function execute_passes_includes_through_to_repository(): void
    {
        $includes = ['variations'];
        $expected = PaginatedListDTO::fromPage(items: [], total: 5, perPage: 20, currentPage: 2);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(20, 2, $includes)
            ->andReturn($expected);

        $this->logger->shouldReceive('info')->once()->with('Listing products', ['page' => 2, 'per_page' => 20, 'includes' => $includes]);
        $this->logger->shouldReceive('info')->once()->with('Listed products', ['total' => 5, 'returned' => 0]);

        $result = $this->useCase->execute(perPage: 20, page: 2, includes: $includes);

        $this->assertSame($expected, $result);
    }

    #[Test]
    public function execute_logs_listing_and_listed_messages_with_correct_context(): void
    {
        $items = ['item1', 'item2', 'item3'];
        $dto = PaginatedListDTO::fromPage(items: $items, total: 100, perPage: 10, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->andReturn($dto);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Listing products', ['page' => 1, 'per_page' => 10, 'includes' => []]);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Listed products', ['total' => 100, 'returned' => 3]);

        $this->useCase->execute(perPage: 10, page: 1);
    }
}
