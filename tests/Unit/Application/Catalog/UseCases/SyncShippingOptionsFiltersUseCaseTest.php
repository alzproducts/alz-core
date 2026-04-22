<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\Commands\ProductFilterChangeCommand;
use App\Application\Catalog\UseCases\SyncShippingOptionsFiltersUseCase;
use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\ShippingOptionsFilterQueryRepositoryInterface;
use App\Domain\Catalog\Product\Enums\ShippingOptionsFilterValue;
use App\Domain\ValueObjects\IntId;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(SyncShippingOptionsFiltersUseCase::class)]
final class SyncShippingOptionsFiltersUseCaseTest extends TestCase
{
    private ShippingOptionsFilterQueryRepositoryInterface&MockInterface $shippingOptionsFilterRepo;

    private CatalogSyncDispatcherInterface&MockInterface $dispatcher;

    private LoggerInterface&MockInterface $logger;

    private SyncShippingOptionsFiltersUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shippingOptionsFilterRepo = Mockery::mock(ShippingOptionsFilterQueryRepositoryInterface::class);
        $this->dispatcher = Mockery::mock(CatalogSyncDispatcherInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new SyncShippingOptionsFiltersUseCase(
            $this->shippingOptionsFilterRepo,
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
        $this->shippingOptionsFilterRepo
            ->shouldReceive('getProductsWithChangedShippingOptionsFilters')
            ->once()
            ->andReturn([]);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncShippingOptionsFilters: starting');

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncShippingOptionsFilters: no products with changed Shipping Options filters');

        $this->dispatcher->shouldNotReceive('dispatchFilterUpdate');

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
            new ProductFilterChangeCommand(IntId::from(1001), 25, [ShippingOptionsFilterValue::NextDayDeliveryAvailable]),
            new ProductFilterChangeCommand(IntId::from(1002), 25, [ShippingOptionsFilterValue::NextDayDeliveryAvailable]),
        ];

        $this->shippingOptionsFilterRepo
            ->shouldReceive('getProductsWithChangedShippingOptionsFilters')
            ->once()
            ->andReturn($changes);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncShippingOptionsFilters: starting');

        $this->dispatcher
            ->shouldReceive('dispatchFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1001),
                25,
                [ShippingOptionsFilterValue::NextDayDeliveryAvailable],
            );

        $this->dispatcher
            ->shouldReceive('dispatchFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1002),
                25,
                [ShippingOptionsFilterValue::NextDayDeliveryAvailable],
            );

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncShippingOptionsFilters: dispatched Shipping Options filter updates', ['count' => 2]);

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Filter Removal Branch (out-of-stock → empty → null dispatch)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_dispatches_null_for_out_of_stock_products(): void
    {
        $changes = [
            new ProductFilterChangeCommand(IntId::from(1001), 25, []),
        ];

        $this->shippingOptionsFilterRepo
            ->shouldReceive('getProductsWithChangedShippingOptionsFilters')
            ->once()
            ->andReturn($changes);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncShippingOptionsFilters: starting');

        $this->dispatcher
            ->shouldReceive('dispatchFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1001),
                25,
                null,
            );

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncShippingOptionsFilters: dispatched Shipping Options filter updates', ['count' => 1]);

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Mixed Values Branch (add + remove in same batch)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_handles_mix_of_in_stock_and_out_of_stock_products(): void
    {
        $changes = [
            new ProductFilterChangeCommand(IntId::from(1001), 25, [ShippingOptionsFilterValue::NextDayDeliveryAvailable]),
            new ProductFilterChangeCommand(IntId::from(1002), 25, []),
            new ProductFilterChangeCommand(IntId::from(1003), 25, [ShippingOptionsFilterValue::NextDayDeliveryAvailable]),
        ];

        $this->shippingOptionsFilterRepo
            ->shouldReceive('getProductsWithChangedShippingOptionsFilters')
            ->once()
            ->andReturn($changes);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncShippingOptionsFilters: starting');

        $this->dispatcher
            ->shouldReceive('dispatchFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1001),
                25,
                [ShippingOptionsFilterValue::NextDayDeliveryAvailable],
            );

        $this->dispatcher
            ->shouldReceive('dispatchFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1002),
                25,
                null,
            );

        $this->dispatcher
            ->shouldReceive('dispatchFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1003),
                25,
                [ShippingOptionsFilterValue::NextDayDeliveryAvailable],
            );

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncShippingOptionsFilters: dispatched Shipping Options filter updates', ['count' => 3]);

        $this->useCase->execute();
    }
}
