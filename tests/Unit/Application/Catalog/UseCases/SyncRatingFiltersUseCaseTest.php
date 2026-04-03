<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\DTOs\ProductFilterChangeDTO;
use App\Application\Catalog\UseCases\SyncRatingFiltersUseCase;
use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\RatingFilterQueryRepositoryInterface;
use App\Domain\Catalog\Product\Enums\RatingFilterValue;
use App\Domain\ValueObjects\IntId;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(SyncRatingFiltersUseCase::class)]
final class SyncRatingFiltersUseCaseTest extends TestCase
{
    private RatingFilterQueryRepositoryInterface&MockInterface $ratingFilterRepo;

    private CatalogSyncDispatcherInterface&MockInterface $dispatcher;

    private LoggerInterface&MockInterface $logger;

    private SyncRatingFiltersUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ratingFilterRepo = Mockery::mock(RatingFilterQueryRepositoryInterface::class);
        $this->dispatcher = Mockery::mock(CatalogSyncDispatcherInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new SyncRatingFiltersUseCase(
            $this->ratingFilterRepo,
            $this->dispatcher,
            $this->logger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Empty Changes Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_logs_starting_and_no_changes_when_no_products_with_changed_filters(): void
    {
        $this->ratingFilterRepo
            ->shouldReceive('getProductsWithChangedRatingFilters')
            ->once()
            ->andReturn([]);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncRatingFilters: starting');

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncRatingFilters: no products with changed rating filters');

        $this->dispatcher->shouldNotReceive('dispatchRatingFilterUpdate');

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Successful Dispatch Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_dispatches_filter_updates_for_changed_products(): void
    {
        $changes = [
            new ProductFilterChangeDTO(IntId::from(1001), 15, [RatingFilterValue::FourStars, RatingFilterValue::FourAndHalfStars]),
            new ProductFilterChangeDTO(IntId::from(1002), 15, [RatingFilterValue::FourStars]),
        ];

        $this->ratingFilterRepo
            ->shouldReceive('getProductsWithChangedRatingFilters')
            ->once()
            ->andReturn($changes);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncRatingFilters: starting');

        $this->dispatcher
            ->shouldReceive('dispatchRatingFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1001),
                15,
                [RatingFilterValue::FourStars, RatingFilterValue::FourAndHalfStars],
            );

        $this->dispatcher
            ->shouldReceive('dispatchRatingFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1002),
                15,
                [RatingFilterValue::FourStars],
            );

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncRatingFilters: dispatched rating filter updates', ['count' => 2]);

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Filter Removal Branch (empty desired values → null)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_dispatches_null_for_products_with_empty_filter_values(): void
    {
        $changes = [
            new ProductFilterChangeDTO(IntId::from(1001), 15, []),
        ];

        $this->ratingFilterRepo
            ->shouldReceive('getProductsWithChangedRatingFilters')
            ->once()
            ->andReturn($changes);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncRatingFilters: starting');

        $this->dispatcher
            ->shouldReceive('dispatchRatingFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1001),
                15,
                null,
            );

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncRatingFilters: dispatched rating filter updates', ['count' => 1]);

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Mixed Values Branch (add + remove in same batch)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_handles_mix_of_add_and_remove_filter_values(): void
    {
        $changes = [
            new ProductFilterChangeDTO(IntId::from(1001), 15, [RatingFilterValue::FourStars, RatingFilterValue::FourAndHalfStars]),
            new ProductFilterChangeDTO(IntId::from(1002), 15, []),
            new ProductFilterChangeDTO(IntId::from(1003), 15, [RatingFilterValue::FourStars]),
        ];

        $this->ratingFilterRepo
            ->shouldReceive('getProductsWithChangedRatingFilters')
            ->once()
            ->andReturn($changes);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncRatingFilters: starting');

        $this->dispatcher
            ->shouldReceive('dispatchRatingFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1001),
                15,
                [RatingFilterValue::FourStars, RatingFilterValue::FourAndHalfStars],
            );

        $this->dispatcher
            ->shouldReceive('dispatchRatingFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1002),
                15,
                null,
            );

        $this->dispatcher
            ->shouldReceive('dispatchRatingFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1003),
                15,
                [RatingFilterValue::FourStars],
            );

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncRatingFilters: dispatched rating filter updates', ['count' => 3]);

        $this->useCase->execute();
    }
}
