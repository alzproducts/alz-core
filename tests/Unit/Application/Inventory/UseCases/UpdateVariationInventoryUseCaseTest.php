<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Inventory\UseCases;

use App\Application\Contracts\Linnworks\InventoryFieldUpdateClientInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Application\Inventory\UseCases\UpdateVariationInventoryUseCase;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Inventory\Commands\UpdateInventoryFieldsCommand;
use App\Domain\Inventory\ValueObjects\InventoryFieldUpdate;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(UpdateVariationInventoryUseCase::class)]
final class UpdateVariationInventoryUseCaseTest extends TestCase
{
    private InventoryFieldUpdateClientInterface&MockInterface $fieldUpdateClient;

    private StockItemRepositoryInterface&MockInterface $stockItemRepository;

    private LoggerInterface&MockInterface $logger;

    private UpdateVariationInventoryUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldUpdateClient = Mockery::mock(InventoryFieldUpdateClientInterface::class);
        $this->stockItemRepository = Mockery::mock(StockItemRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new UpdateVariationInventoryUseCase(
            $this->fieldUpdateClient,
            $this->stockItemRepository,
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
    | Happy Path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_calls_client_then_repo_and_does_not_warn_when_row_updated(): void
    {
        $sku = Sku::fromTrusted('TEST-SKU');
        $update = InventoryFieldUpdate::jit(true);

        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->once()
            ->withArgs(static fn($s, $u) => $s->value === 'TEST-SKU' && $u->value === 'true');

        $this->stockItemRepository->shouldReceive('updateInventoryFieldsBySku')
            ->once()
            ->withArgs(static fn($s, $u) => $s->value === 'TEST-SKU')
            ->andReturn(1);

        $this->logger->shouldNotReceive('warning');

        $this->useCase->execute(new UpdateInventoryFieldsCommand($sku, [$update]));

        $this->assertTrue(true);
    }

    /*
    |--------------------------------------------------------------------------
    | Zero Affected Rows (Local Mirror Missing)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_logs_warning_when_no_local_row_was_updated(): void
    {
        $sku = Sku::fromTrusted('GHOST-SKU');
        $update = InventoryFieldUpdate::minimumLevel(10);

        $this->fieldUpdateClient->shouldReceive('updateFields')->once();

        $this->stockItemRepository->shouldReceive('updateInventoryFieldsBySku')
            ->once()
            ->andReturn(0);

        $this->logger->shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $msg, array $ctx): bool => $ctx['sku'] === 'GHOST-SKU');

        $this->useCase->execute(new UpdateInventoryFieldsCommand($sku, [$update]));

        $this->assertTrue(true);
    }

    /*
    |--------------------------------------------------------------------------
    | Client Exception — Repo Must Not Be Called
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_bubbles_client_exception_without_calling_repo(): void
    {
        $sku = Sku::fromTrusted('MISSING-SKU');
        $update = InventoryFieldUpdate::jit(false);

        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->once()
            ->andThrow(new ResourceNotFoundException('Linnworks', 'StockItem', 'MISSING-SKU'));

        $this->stockItemRepository->shouldNotReceive('updateInventoryFieldsBySku');

        $this->expectException(ResourceNotFoundException::class);

        $this->useCase->execute(new UpdateInventoryFieldsCommand($sku, [$update]));
    }
}
