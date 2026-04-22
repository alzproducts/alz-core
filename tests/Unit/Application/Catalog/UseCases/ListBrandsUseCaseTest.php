<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\UseCases\ListBrandsUseCase;
use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Domain\Catalog\Brand\Enums\BrandInclude;
use App\Domain\ValueObjects\PaginatedList;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(ListBrandsUseCase::class)]
final class ListBrandsUseCaseTest extends TestCase
{
    private BrandRepositoryInterface&MockInterface $brandRepository;

    private LoggerInterface&MockInterface $logger;

    private ListBrandsUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->brandRepository = Mockery::mock(BrandRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new ListBrandsUseCase(
            $this->brandRepository,
            $this->logger,
        );
    }

    #[Test]
    public function execute_delegates_to_repository_and_returns_paginated_dto(): void
    {
        $expected = PaginatedList::fromPage(items: [], total: 0, perPage: 10, currentPage: 1);

        $this->brandRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(10, 1, [], false)
            ->andReturn($expected);

        $this->logger->shouldReceive('info')->once()->with('Listing brands', ['page' => 1, 'per_page' => 10, 'includes' => [], 'include_inactive' => false]);
        $this->logger->shouldReceive('info')->once()->with('Listed brands', ['total' => 0, 'returned' => 0]);

        $result = $this->useCase->execute(perPage: 10, page: 1);

        $this->assertSame($expected, $result);
    }

    #[Test]
    public function execute_passes_includes_and_include_inactive_through_to_repository(): void
    {
        $includes = [BrandInclude::CustomFields];
        $expected = PaginatedList::fromPage(items: [], total: 5, perPage: 20, currentPage: 2);

        $this->brandRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(20, 2, $includes, true)
            ->andReturn($expected);

        $this->logger->shouldReceive('info')->once()->with('Listing brands', ['page' => 2, 'per_page' => 20, 'includes' => ['custom_fields'], 'include_inactive' => true]);
        $this->logger->shouldReceive('info')->once()->with('Listed brands', ['total' => 5, 'returned' => 0]);

        $result = $this->useCase->execute(perPage: 20, page: 2, includes: $includes, includeInactive: true);

        $this->assertSame($expected, $result);
    }

    #[Test]
    public function execute_logs_listing_and_listed_messages_with_correct_context(): void
    {
        $items = ['item1', 'item2', 'item3'];
        $dto = PaginatedList::fromPage(items: $items, total: 100, perPage: 10, currentPage: 1);

        $this->brandRepository
            ->shouldReceive('paginate')
            ->once()
            ->andReturn($dto);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Listing brands', ['page' => 1, 'per_page' => 10, 'includes' => [], 'include_inactive' => false]);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Listed brands', ['total' => 100, 'returned' => 3]);

        $this->useCase->execute(perPage: 10, page: 1);
    }
}
