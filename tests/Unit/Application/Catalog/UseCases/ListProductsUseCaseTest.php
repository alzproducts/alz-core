<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\Queries\ProductListQueryParams;
use App\Application\Catalog\Results\ProductListEntryDTO;
use App\Application\Catalog\Results\ProductListPageResult;
use App\Application\Catalog\UseCases\ListProductsUseCase;
use App\Application\Contracts\Catalog\ProductListReaderInterface;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Tests\TestCase;

#[CoversClass(ListProductsUseCase::class)]
final class ListProductsUseCaseTest extends TestCase
{
    private ProductListReaderInterface&MockInterface $reader;

    private LoggerInterface&MockInterface $logger;

    private ListProductsUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = Mockery::mock(ProductListReaderInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new ListProductsUseCase(
            $this->reader,
            $this->logger,
        );
    }

    #[Test]
    public function execute_delegates_to_reader_and_returns_its_page(): void
    {
        $expected = self::page(total: 0, perPage: 10, currentPage: 1, entries: []);
        $query = new ProductListQueryParams(pagination: PageRequest::from(1, 10));

        $this->reader
            ->shouldReceive('page')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->pagination->perPage === 10 && $q->pagination->page === 1 && $q->includes === []))
            ->andReturn($expected);

        $this->logger->shouldReceive('info')->once()->with('Listing products', ['page' => 1, 'per_page' => 10, 'includes' => [], 'sort' => null, 'direction' => 'asc']);
        $this->logger->shouldReceive('info')->once()->with('Listed products', ['total' => 0, 'returned' => 0, 'fragment_hits' => 0]);

        $result = $this->useCase->execute($query);

        $this->assertSame($expected, $result);
    }

    #[Test]
    public function execute_passes_includes_through_to_reader(): void
    {
        $includes = [ProductInclude::Variations];
        $expected = self::page(total: 5, perPage: 20, currentPage: 2, entries: []);
        $query = new ProductListQueryParams(pagination: PageRequest::from(2, 20), includes: $includes);

        $this->reader
            ->shouldReceive('page')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->pagination->perPage === 20 && $q->pagination->page === 2 && $q->includes === $includes))
            ->andReturn($expected);

        $this->logger->shouldReceive('info')->once()->with('Listing products', ['page' => 2, 'per_page' => 20, 'includes' => $includes, 'sort' => null, 'direction' => 'asc']);
        $this->logger->shouldReceive('info')->once()->with('Listed products', ['total' => 5, 'returned' => 0, 'fragment_hits' => 0]);

        $result = $this->useCase->execute($query);

        $this->assertSame($expected, $result);
    }

    #[Test]
    public function execute_logs_returned_count_and_fragment_hits(): void
    {
        $entries = [
            new ProductListEntryDTO('p1', 'f1', '{"id":1}', null),
            new ProductListEntryDTO('p2', 'f2', '{"id":2}', null),
            new ProductListEntryDTO('p3', 'f3', null, new ReflectionClass(ProductView::class)->newInstanceWithoutConstructor()),
        ];

        $this->reader
            ->shouldReceive('page')
            ->once()
            ->andReturn(self::page(total: 100, perPage: 10, currentPage: 1, entries: $entries));

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Listing products', ['page' => 1, 'per_page' => 10, 'includes' => [], 'sort' => null, 'direction' => 'asc']);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Listed products', ['total' => 100, 'returned' => 3, 'fragment_hits' => 2]);

        $this->useCase->execute(new ProductListQueryParams(pagination: PageRequest::from(1, 10)));
    }

    /**
     * @param list<ProductListEntryDTO> $entries
     */
    private static function page(int $total, int $perPage, int $currentPage, array $entries): ProductListPageResult
    {
        return new ProductListPageResult(total: $total, perPage: $perPage, currentPage: $currentPage, shape: 'none', entries: $entries);
    }
}
