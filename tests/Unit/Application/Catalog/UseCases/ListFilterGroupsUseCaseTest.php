<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\UseCases\ListFilterGroupsUseCase;
use App\Application\Contracts\Shopwired\FilterGroupRepositoryInterface;
use App\Domain\ValueObjects\PaginatedList;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(ListFilterGroupsUseCase::class)]
final class ListFilterGroupsUseCaseTest extends TestCase
{
    private FilterGroupRepositoryInterface&MockInterface $filterGroupRepository;

    private LoggerInterface&MockInterface $logger;

    private ListFilterGroupsUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filterGroupRepository = Mockery::mock(FilterGroupRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new ListFilterGroupsUseCase(
            $this->filterGroupRepository,
            $this->logger,
        );
    }

    #[Test]
    public function execute_delegates_to_repository_and_returns_paginated_dto(): void
    {
        $expected = PaginatedList::fromPage(items: [], total: 0, perPage: 10, currentPage: 1);

        $this->filterGroupRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(10, 1)
            ->andReturn($expected);

        $this->logger->shouldReceive('info')->once()->with('Listing filter groups', ['page' => 1, 'per_page' => 10]);
        $this->logger->shouldReceive('info')->once()->with('Listed filter groups', ['total' => 0, 'returned' => 0]);

        $result = $this->useCase->execute(perPage: 10, page: 1);

        $this->assertSame($expected, $result);
    }

    #[Test]
    public function execute_logs_listing_and_listed_messages_with_correct_context(): void
    {
        $items = ['item1', 'item2'];
        $dto = PaginatedList::fromPage(items: $items, total: 50, perPage: 10, currentPage: 1);

        $this->filterGroupRepository
            ->shouldReceive('paginate')
            ->once()
            ->andReturn($dto);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Listing filter groups', ['page' => 1, 'per_page' => 10]);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Listed filter groups', ['total' => 50, 'returned' => 2]);

        $this->useCase->execute(perPage: 10, page: 1);
    }

    #[Test]
    public function execute_passes_per_page_and_page_through_to_repository(): void
    {
        $expected = PaginatedList::fromPage(items: [], total: 5, perPage: 20, currentPage: 3);

        $this->filterGroupRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(20, 3)
            ->andReturn($expected);

        $this->logger->shouldReceive('info')->once()->with('Listing filter groups', ['page' => 3, 'per_page' => 20]);
        $this->logger->shouldReceive('info')->once()->with('Listed filter groups', ['total' => 5, 'returned' => 0]);

        $result = $this->useCase->execute(perPage: 20, page: 3);

        $this->assertSame($expected, $result);
    }
}
