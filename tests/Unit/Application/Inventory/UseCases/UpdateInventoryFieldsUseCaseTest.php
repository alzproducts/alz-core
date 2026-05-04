<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Inventory\UseCases;

use App\Application\Contracts\Linnworks\InventoryFieldUpdateClientInterface;
use App\Application\Contracts\Linnworks\LinnworksSyncDispatcherInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Application\Inventory\UseCases\UpdateInventoryFieldsUseCase;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Inventory\Commands\UpdateInventoryFieldsCommand;
use App\Domain\Inventory\ValueObjects\InventoryFieldUpdate;
use App\Domain\ValueObjects\Guid;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(UpdateInventoryFieldsUseCase::class)]
final class UpdateInventoryFieldsUseCaseTest extends TestCase
{
    private InventoryFieldUpdateClientInterface&MockInterface $fieldUpdateClient;

    private StockItemRepositoryInterface&MockInterface $stockItemRepository;

    private LinnworksSyncDispatcherInterface&MockInterface $syncDispatcher;

    private LoggerInterface&MockInterface $logger;

    private UpdateInventoryFieldsUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldUpdateClient = Mockery::mock(InventoryFieldUpdateClientInterface::class);
        $this->stockItemRepository = Mockery::mock(StockItemRepositoryInterface::class);
        $this->syncDispatcher = Mockery::mock(LinnworksSyncDispatcherInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new UpdateInventoryFieldsUseCase(
            $this->fieldUpdateClient,
            $this->stockItemRepository,
            $this->syncDispatcher,
            $this->logger,
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Happy path — every SKU succeeds
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_all_succeeded_when_every_api_call_and_db_write_succeed(): void
    {
        $commands = [
            new UpdateInventoryFieldsCommand(Sku::fromTrusted('SKU-1'), [InventoryFieldUpdate::jit(true)]),
            new UpdateInventoryFieldsCommand(Sku::fromTrusted('SKU-2'), [InventoryFieldUpdate::minimumLevel(5)]),
            new UpdateInventoryFieldsCommand(
                Sku::fromTrusted('SKU-3'),
                [InventoryFieldUpdate::jit(false), InventoryFieldUpdate::minimumLevel(10)],
            ),
        ];

        $this->fieldUpdateClient->shouldReceive('updateFields')->times(3);

        $this->stockItemRepository->shouldReceive('bulkUpdateInventoryFieldsBySkus')
            ->once()
            ->withArgs(
                static fn(array $updatesBySku): bool
                => \array_keys($updatesBySku) === ['SKU-1', 'SKU-2', 'SKU-3'],
            )
            ->andReturn(3);

        $this->stockItemRepository->shouldNotReceive('resolveStockItemIdsBySkus');
        $this->syncDispatcher->shouldNotReceive('dispatchStockItemSync');

        $result = $this->useCase->execute($commands);

        $this->assertSame(3, $result->total);
        $this->assertSame(3, $result->succeeded);
        $this->assertSame([], $result->permanentFailures);
        $this->assertSame([], $result->temporaryFailures);
    }

    /*
    |--------------------------------------------------------------------------
    | Partial success — mix of permanent / transient / unknown failures
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_partitions_failures_by_exception_base_type_and_only_writes_succeeded_skus(): void
    {
        $commands = [
            new UpdateInventoryFieldsCommand(Sku::fromTrusted('GOOD-1'), [InventoryFieldUpdate::jit(true)]),
            new UpdateInventoryFieldsCommand(Sku::fromTrusted('PERM-2'), [InventoryFieldUpdate::jit(true)]),
            new UpdateInventoryFieldsCommand(Sku::fromTrusted('TRANS-3'), [InventoryFieldUpdate::jit(true)]),
            new UpdateInventoryFieldsCommand(Sku::fromTrusted('UNKNOWN-4'), [InventoryFieldUpdate::jit(true)]),
            new UpdateInventoryFieldsCommand(Sku::fromTrusted('GOOD-5'), [InventoryFieldUpdate::minimumLevel(7)]),
        ];

        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->ordered()
            ->once()
            ->withArgs(static fn($identifier): bool => $identifier->value === 'GOOD-1');
        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->ordered()
            ->once()
            ->andThrow(new ResourceNotFoundException('Linnworks', 'StockItem', 'PERM-2'));
        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->ordered()
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Linnworks'));
        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->ordered()
            ->once()
            ->andThrow(new RuntimeException('totally unexpected'));
        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->ordered()
            ->once()
            ->withArgs(static fn($identifier): bool => $identifier->value === 'GOOD-5');

        $this->stockItemRepository->shouldReceive('bulkUpdateInventoryFieldsBySkus')
            ->once()
            ->withArgs(
                static fn(array $updatesBySku): bool
                => \array_keys($updatesBySku) === ['GOOD-1', 'GOOD-5'],
            )
            ->andReturn(2);

        $result = $this->useCase->execute($commands);

        $this->assertSame(5, $result->total);
        $this->assertSame(2, $result->succeeded);

        $permanentSkus = \array_column($result->permanentFailures, 'identifier');
        $this->assertSame(['PERM-2', 'UNKNOWN-4'], $permanentSkus);

        $temporarySkus = \array_column($result->temporaryFailures, 'identifier');
        $this->assertSame(['TRANS-3'], $temporarySkus);
    }

    /*
    |--------------------------------------------------------------------------
    | All API calls fail — bulk write must not be attempted
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_skips_the_db_write_entirely_when_every_api_call_fails(): void
    {
        $commands = [
            new UpdateInventoryFieldsCommand(Sku::fromTrusted('FAIL-1'), [InventoryFieldUpdate::jit(true)]),
            new UpdateInventoryFieldsCommand(Sku::fromTrusted('FAIL-2'), [InventoryFieldUpdate::minimumLevel(3)]),
        ];

        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->ordered()
            ->once()
            ->andThrow(new AuthenticationExpiredException('Linnworks'));
        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->ordered()
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Linnworks'));

        $this->stockItemRepository->shouldNotReceive('bulkUpdateInventoryFieldsBySkus');
        $this->stockItemRepository->shouldNotReceive('resolveStockItemIdsBySkus');
        $this->syncDispatcher->shouldNotReceive('dispatchStockItemSync');

        $result = $this->useCase->execute($commands);

        $this->assertSame(2, $result->total);
        $this->assertSame(0, $result->succeeded);
        $this->assertCount(1, $result->permanentFailures);
        $this->assertCount(1, $result->temporaryFailures);
        $this->assertSame('FAIL-1', $result->permanentFailures[0]['identifier']);
        $this->assertSame('FAIL-2', $result->temporaryFailures[0]['identifier']);
    }

    /*
    |--------------------------------------------------------------------------
    | DB write failure — succeeded items demoted, reconciliation syncs dispatched
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_demotes_succeeded_items_and_dispatches_reconciliation_syncs_when_the_db_write_fails(): void
    {
        $commands = [
            new UpdateInventoryFieldsCommand(Sku::fromTrusted('OK-A'), [InventoryFieldUpdate::jit(true)]),
            new UpdateInventoryFieldsCommand(Sku::fromTrusted('OK-B'), [InventoryFieldUpdate::minimumLevel(2)]),
            new UpdateInventoryFieldsCommand(Sku::fromTrusted('PERM-C'), [InventoryFieldUpdate::jit(false)]),
        ];

        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->ordered()
            ->once()
            ->withArgs(static fn($identifier): bool => $identifier->value === 'OK-A');
        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->ordered()
            ->once()
            ->withArgs(static fn($identifier): bool => $identifier->value === 'OK-B');
        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->ordered()
            ->once()
            ->andThrow(new ResourceNotFoundException('Linnworks', 'StockItem', 'PERM-C'));

        $this->stockItemRepository->shouldReceive('bulkUpdateInventoryFieldsBySkus')
            ->once()
            ->andThrow(new DatabaseOperationFailedException('bulkUpdate', 'connection lost'));

        $guidA = new Guid('11111111-1111-4111-8111-111111111111');
        $guidB = new Guid('22222222-2222-4222-8222-222222222222');

        $this->stockItemRepository->shouldReceive('resolveStockItemIdsBySkus')
            ->once()
            ->withArgs(static function (...$skus) {
                $values = \array_map(static fn(Sku $s): string => $s->value, $skus);
                return $values === ['OK-A', 'OK-B'];
            })
            ->andReturn(['OK-A' => $guidA, 'OK-B' => $guidB]);

        $this->syncDispatcher->shouldReceive('dispatchStockItemSync')
            ->once()
            ->with(Mockery::on(static fn(Guid $g): bool => $g->value === $guidA->value));
        $this->syncDispatcher->shouldReceive('dispatchStockItemSync')
            ->once()
            ->with(Mockery::on(static fn(Guid $g): bool => $g->value === $guidB->value));

        $result = $this->useCase->execute($commands);

        // Both API-succeeded items are demoted to permanent failures alongside the genuine PERM-C failure.
        $this->assertSame(3, $result->total);
        $this->assertSame(0, $result->succeeded);
        $this->assertSame([], $result->temporaryFailures);

        $permanentSkus = \array_column($result->permanentFailures, 'identifier');
        $this->assertSame(['PERM-C', 'OK-A', 'OK-B'], $permanentSkus);

        $this->assertSame(
            'Local DB write failed; reconciliation sync dispatched',
            $result->permanentFailures[1]['error'],
        );
        $this->assertSame(
            'Local DB write failed; reconciliation sync dispatched',
            $result->permanentFailures[2]['error'],
        );
    }

    #[Test]
    public function it_demotes_succeeded_items_when_the_db_layer_is_temporarily_unavailable(): void
    {
        $commands = [
            new UpdateInventoryFieldsCommand(Sku::fromTrusted('OK-X'), [InventoryFieldUpdate::jit(true)]),
            new UpdateInventoryFieldsCommand(Sku::fromTrusted('OK-Y'), [InventoryFieldUpdate::minimumLevel(4)]),
        ];

        $this->fieldUpdateClient->shouldReceive('updateFields')->times(2);

        $this->stockItemRepository->shouldReceive('bulkUpdateInventoryFieldsBySkus')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('postgres'));

        $guidX = new Guid('33333333-3333-4333-8333-333333333333');
        $guidY = new Guid('44444444-4444-4444-8444-444444444444');

        $this->stockItemRepository->shouldReceive('resolveStockItemIdsBySkus')
            ->once()
            ->andReturn(['OK-X' => $guidX, 'OK-Y' => $guidY]);

        $this->syncDispatcher->shouldReceive('dispatchStockItemSync')->twice();

        $result = $this->useCase->execute($commands);

        $this->assertSame(2, $result->total);
        $this->assertSame(0, $result->succeeded);
        $this->assertSame([], $result->temporaryFailures);
        $this->assertCount(2, $result->permanentFailures);
        $this->assertSame(['OK-X', 'OK-Y'], \array_column($result->permanentFailures, 'identifier'));
    }

    /*
    |--------------------------------------------------------------------------
    | Logging — aggregate counts only
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_logs_aggregate_counts_at_start_and_completion(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $useCase = new UpdateInventoryFieldsUseCase(
            $this->fieldUpdateClient,
            $this->stockItemRepository,
            $this->syncDispatcher,
            $logger,
        );

        $commands = [
            new UpdateInventoryFieldsCommand(Sku::fromTrusted('LOG-1'), [InventoryFieldUpdate::jit(true)]),
            new UpdateInventoryFieldsCommand(Sku::fromTrusted('LOG-2'), [InventoryFieldUpdate::jit(true)]),
        ];

        $logger->shouldReceive('info')
            ->ordered()
            ->once()
            ->withArgs(static fn(string $msg, array $ctx): bool => $ctx['count'] === 2);

        $logger->shouldReceive('info')
            ->ordered()
            ->once()
            ->withArgs(
                static fn(string $msg, array $ctx): bool
                => $ctx['total'] === 2 && $ctx['succeeded'] === 2 && $ctx['failed'] === 0,
            );

        $this->fieldUpdateClient->shouldReceive('updateFields')->times(2);
        $this->stockItemRepository->shouldReceive('bulkUpdateInventoryFieldsBySkus')->once()->andReturn(2);

        $useCase->execute($commands);
    }
}
