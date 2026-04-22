<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\Commands\ProductFilterChangeCommand;
use App\Application\Catalog\UseCases\SyncVatReliefFiltersUseCase;
use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\VatReliefFilterQueryRepositoryInterface;
use App\Domain\Catalog\Product\Enums\VatReliefFilterValue;
use App\Domain\ValueObjects\IntId;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(SyncVatReliefFiltersUseCase::class)]
final class SyncVatReliefFiltersUseCaseTest extends TestCase
{
    private VatReliefFilterQueryRepositoryInterface&MockInterface $vatReliefFilterRepo;

    private CatalogSyncDispatcherInterface&MockInterface $dispatcher;

    private LoggerInterface&MockInterface $logger;

    private SyncVatReliefFiltersUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vatReliefFilterRepo = Mockery::mock(VatReliefFilterQueryRepositoryInterface::class);
        $this->dispatcher = Mockery::mock(CatalogSyncDispatcherInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new SyncVatReliefFiltersUseCase(
            $this->vatReliefFilterRepo,
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
        $this->vatReliefFilterRepo
            ->shouldReceive('getProductsWithChangedVatReliefFilters')
            ->once()
            ->andReturn([]);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncVatReliefFilters: starting');

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncVatReliefFilters: no products with changed VAT-relief filters');

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
            new ProductFilterChangeCommand(IntId::from(1001), 2, [VatReliefFilterValue::Yes]),
            new ProductFilterChangeCommand(IntId::from(1002), 2, [VatReliefFilterValue::Yes]),
        ];

        $this->vatReliefFilterRepo
            ->shouldReceive('getProductsWithChangedVatReliefFilters')
            ->once()
            ->andReturn($changes);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncVatReliefFilters: starting');

        $this->dispatcher
            ->shouldReceive('dispatchFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1001),
                2,
                [VatReliefFilterValue::Yes],
            );

        $this->dispatcher
            ->shouldReceive('dispatchFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1002),
                2,
                [VatReliefFilterValue::Yes],
            );

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncVatReliefFilters: dispatched VAT-relief filter updates', ['count' => 2]);

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
            new ProductFilterChangeCommand(IntId::from(1001), 2, []),
        ];

        $this->vatReliefFilterRepo
            ->shouldReceive('getProductsWithChangedVatReliefFilters')
            ->once()
            ->andReturn($changes);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncVatReliefFilters: starting');

        $this->dispatcher
            ->shouldReceive('dispatchFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1001),
                2,
                null,
            );

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncVatReliefFilters: dispatched VAT-relief filter updates', ['count' => 1]);

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
            new ProductFilterChangeCommand(IntId::from(1001), 2, [VatReliefFilterValue::Yes]),
            new ProductFilterChangeCommand(IntId::from(1002), 2, []),
            new ProductFilterChangeCommand(IntId::from(1003), 2, [VatReliefFilterValue::Yes]),
        ];

        $this->vatReliefFilterRepo
            ->shouldReceive('getProductsWithChangedVatReliefFilters')
            ->once()
            ->andReturn($changes);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncVatReliefFilters: starting');

        $this->dispatcher
            ->shouldReceive('dispatchFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1001),
                2,
                [VatReliefFilterValue::Yes],
            );

        $this->dispatcher
            ->shouldReceive('dispatchFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1002),
                2,
                null,
            );

        $this->dispatcher
            ->shouldReceive('dispatchFilterUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1003),
                2,
                [VatReliefFilterValue::Yes],
            );

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncVatReliefFilters: dispatched VAT-relief filter updates', ['count' => 3]);

        $this->useCase->execute();
    }
}
