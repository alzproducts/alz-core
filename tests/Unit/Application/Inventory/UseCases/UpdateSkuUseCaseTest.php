<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Inventory\UseCases;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\LockManagerInterface;
use App\Application\Contracts\Operations\SkuChangeRepositoryInterface;
use App\Application\Contracts\Shopwired\BasicProductUpdateClientInterface;
use App\Application\Inventory\UseCases\UpdateSkuUseCase;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Inventory\SkuGenerationFailedException;
use App\Domain\Exceptions\Inventory\SkuUpdateFailedException;
use App\Domain\Inventory\Commands\UpdateSkuCommand;
use App\Domain\Inventory\Enums\SkuUpdateReason;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Tests for UpdateSkuUseCase branching logic.
 *
 * Per TestingStrategy.md: Test orchestration decisions (when X, do Y),
 * business workflow branches, and error handling paths.
 */
#[CoversClass(UpdateSkuUseCase::class)]
final class UpdateSkuUseCaseTest extends TestCase
{
    private InventoryClientInterface&MockInterface $inventoryClient;

    private InventoryUpdateClientInterface&MockInterface $inventoryUpdateClient;

    private BasicProductUpdateClientInterface&MockInterface $shopwiredClient;

    private SkuChangeRepositoryInterface&MockInterface $auditRepository;

    private LockManagerInterface&MockInterface $lockManager;

    private LoggerInterface&MockInterface $logger;

    private UpdateSkuUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryClient = Mockery::mock(InventoryClientInterface::class)->shouldIgnoreMissing();
        $this->inventoryUpdateClient = Mockery::mock(InventoryUpdateClientInterface::class)->shouldIgnoreMissing();
        $this->shopwiredClient = Mockery::mock(BasicProductUpdateClientInterface::class);
        $this->auditRepository = Mockery::mock(SkuChangeRepositoryInterface::class);
        $this->lockManager = Mockery::mock(LockManagerInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        // Lock manager executes callback immediately (pass-through for unit tests)
        $this->lockManager->shouldReceive('withLock')
            ->andReturnUsing(static fn(string $name, int $timeout, callable $callback) => $callback());

        $this->useCase = new UpdateSkuUseCase(
            $this->inventoryClient,
            $this->inventoryUpdateClient,
            $this->shopwiredClient,
            $this->auditRepository,
            $this->lockManager,
            $this->logger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Happy Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_updates_sku_with_provided_value(): void
    {
        $command = UpdateSkuCommand::provided(
            oldSku: 'OLD-SKU',
            newSku: Sku::fromTrusted('NEW-SKU'),
            reason: SkuUpdateReason::FixSkuMismatch,
        );

        $this->auditRepository->shouldReceive('create')
            ->once()
            ->andReturn('audit-id-123');

        $this->inventoryUpdateClient->shouldReceive('updateSku')
            ->once()
            ->withArgs(static fn($old, $new) => $old->value === 'OLD-SKU' && $new->value === 'NEW-SKU');

        $this->shopwiredClient->shouldReceive('update')
            ->once()
            ->withArgs(static fn($cmd) => $cmd->currentSku === 'OLD-SKU' && $cmd->newSku->value === 'NEW-SKU');

        $this->auditRepository->shouldReceive('markComplete')
            ->once()
            ->with('audit-id-123');

        $this->useCase->execute($command);

        // No exception = success
        $this->assertTrue(true);
    }

    #[Test]
    public function it_generates_sku_when_type_is_generated(): void
    {
        $command = UpdateSkuCommand::generated(
            oldSku: 'OLD-SKU',
            reason: SkuUpdateReason::ShortenLongSku,
        );

        $generatedSku = Sku::fromTrusted('AUTO-12345');

        $this->inventoryClient->shouldReceive('getNewItemNumber')
            ->once()
            ->andReturn($generatedSku);

        $this->auditRepository->shouldReceive('create')
            ->once()
            ->andReturn('audit-id-456');

        $this->inventoryUpdateClient->shouldReceive('updateSku')
            ->once()
            ->withArgs(static fn($old, $new) => $new->value === 'AUTO-12345');

        $this->shopwiredClient->shouldReceive('update')
            ->once();

        $this->auditRepository->shouldReceive('markComplete')
            ->once();

        $this->useCase->execute($command);

        $this->assertTrue(true);
    }

    /*
    |--------------------------------------------------------------------------
    | SKU Generation Failure Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_sku_generation_failed_when_auto_generate_fails(): void
    {
        $command = UpdateSkuCommand::generated(
            oldSku: 'OLD-SKU',
            reason: SkuUpdateReason::ShortenLongSku,
        );

        $this->inventoryClient->shouldReceive('getNewItemNumber')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Linnworks'));

        $this->expectException(SkuGenerationFailedException::class);

        $this->useCase->execute($command);
    }

    /*
    |--------------------------------------------------------------------------
    | Linnworks Failure Tests (No Compensation Needed)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_records_error_and_rethrows_when_linnworks_update_fails(): void
    {
        $command = UpdateSkuCommand::provided(
            oldSku: 'OLD-SKU',
            newSku: Sku::fromTrusted('NEW-SKU'),
            reason: SkuUpdateReason::Other,
        );

        $this->auditRepository->shouldReceive('create')
            ->once()
            ->andReturn('audit-id');

        $originalException = new ExternalServiceUnavailableException('Linnworks');

        $this->inventoryUpdateClient->shouldReceive('updateSku')
            ->once()
            ->andThrow($originalException);

        $this->auditRepository->shouldReceive('recordError')
            ->once()
            ->with('audit-id', Mockery::pattern('/Linnworks failed/'));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->useCase->execute($command);
    }

    /*
    |--------------------------------------------------------------------------
    | Compensation Tests (ShopWired Fails, Linnworks Rolled Back)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_sku_update_failed_when_compensation_fails(): void
    {
        $command = UpdateSkuCommand::provided(
            oldSku: 'OLD-SKU',
            newSku: Sku::fromTrusted('NEW-SKU'),
            reason: SkuUpdateReason::FixSkuMismatch,
        );

        $this->auditRepository->shouldReceive('create')->andReturn('audit-id');

        // First call succeeds, second call (compensation) fails
        $this->inventoryUpdateClient->shouldReceive('updateSku')
            ->once()
            ->ordered();

        $this->inventoryUpdateClient->shouldReceive('updateSku')
            ->once()
            ->ordered()
            ->andThrow(new ExternalServiceUnavailableException('Linnworks'));

        // ShopWired fails, triggering compensation
        $this->shopwiredClient->shouldReceive('update')
            ->once()
            ->andThrow(new InvalidApiRequestException('Shopwired', 'Error'));

        $this->auditRepository->shouldReceive('recordError')
            ->once()
            ->with('audit-id', Mockery::pattern('/compensation failed/'));

        // SkuUpdateFailedException indicates systems are out of sync - DO NOT RETRY
        $this->expectException(SkuUpdateFailedException::class);

        $this->useCase->execute($command);
    }
}
