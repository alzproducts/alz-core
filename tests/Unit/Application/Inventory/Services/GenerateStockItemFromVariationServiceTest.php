<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Inventory\Services;

use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\Shopwired\BasicProductUpdateClientInterface;
use App\Application\Inventory\Params\CreateStockItemParams;
use App\Application\Inventory\Services\GenerateStockItemFromVariationService;
use App\Application\Inventory\Services\LinnworksStockItemCreatorService;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\TaxRate;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Tests for GenerateStockItemFromVariationService.
 *
 * Per TestingStrategy.md: Tests cross-system coordination between
 * Linnworks item creation and ShopWired SKU update, with rollback.
 */
#[CoversClass(GenerateStockItemFromVariationService::class)]
final class GenerateStockItemFromVariationServiceTest extends TestCase
{
    private LinnworksStockItemCreatorService&MockInterface $stockItemCreator;

    private InventoryUpdateClientInterface&MockInterface $inventoryUpdateClient;

    private BasicProductUpdateClientInterface&MockInterface $shopwiredUpdateClient;

    private LoggerInterface&MockInterface $logger;

    private GenerateStockItemFromVariationService $service;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->stockItemCreator = Mockery::mock(LinnworksStockItemCreatorService::class);
        $this->inventoryUpdateClient = Mockery::mock(InventoryUpdateClientInterface::class);
        $this->shopwiredUpdateClient = Mockery::mock(BasicProductUpdateClientInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->service = new GenerateStockItemFromVariationService(
            $this->stockItemCreator,
            $this->inventoryUpdateClient,
            $this->shopwiredUpdateClient,
            $this->logger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Happy Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_item_and_updates_shopwired(): void
    {
        $params = $this->createParams();
        $variationId = 98765;
        $generatedSku = Sku::fromTrusted('GEN-12345');
        $stockItemId = Guid::fromTrusted('550e8400-e29b-41d4-a716-446655440010');

        // Linnworks creation succeeds
        $this->stockItemCreator->shouldReceive('create')
            ->once()
            ->with($params)
            ->andReturn([$generatedSku, $stockItemId]);

        // ShopWired update succeeds
        $this->shopwiredUpdateClient->shouldReceive('update')
            ->once()
            ->withArgs(static fn($cmd) => $cmd->identifier->value === $variationId
                    && $cmd->newSku->value === 'GEN-12345');

        $result = $this->service->generate($params, $variationId);

        $this->assertInstanceOf(Sku::class, $result);
        $this->assertSame('GEN-12345', $result->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Linnworks Failure Tests (Exception Bubbles Up)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_propagates_linnworks_creation_failure(): void
    {
        $params = $this->createParams();
        $variationId = 11111;

        // Linnworks creation fails
        $this->stockItemCreator->shouldReceive('create')
            ->once()
            ->andThrow(new LockAcquisitionException('sku-generation', 30));

        // ShopWired never called
        $this->shopwiredUpdateClient->shouldNotReceive('update');

        // No rollback needed (nothing created)
        $this->inventoryUpdateClient->shouldNotReceive('deleteInventoryItem');

        $this->expectException(LockAcquisitionException::class);

        $this->service->generate($params, $variationId);
    }

    /*
    |--------------------------------------------------------------------------
    | ShopWired Failure Tests (Linnworks Rollback Triggered)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_rolls_back_linnworks_item_when_shopwired_fails(): void
    {
        $params = $this->createParams();
        $variationId = 22222;
        $generatedSku = Sku::fromTrusted('ORPHAN-SKU');
        $stockItemId = Guid::fromTrusted('550e8400-e29b-41d4-a716-446655440011');

        // Linnworks creation succeeds
        $this->stockItemCreator->shouldReceive('create')
            ->once()
            ->andReturn([$generatedSku, $stockItemId]);

        // ShopWired update fails
        $this->shopwiredUpdateClient->shouldReceive('update')
            ->once()
            ->andThrow(new InvalidApiRequestException('ShopWired', 'SKU already exists'));

        // Rollback triggered - delete Linnworks item
        $this->inventoryUpdateClient->shouldReceive('deleteInventoryItem')
            ->once()
            ->with(Mockery::on(static fn($id) => $id->value === '550e8400-e29b-41d4-a716-446655440011'));

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('SKU already exists');

        $this->service->generate($params, $variationId);
    }

    #[Test]
    public function it_rethrows_original_exception_after_rollback(): void
    {
        $params = $this->createParams();
        $variationId = 33333;
        $generatedSku = Sku::fromTrusted('RETRY-SKU');
        $stockItemId = Guid::fromTrusted('550e8400-e29b-41d4-a716-446655440012');

        // Linnworks succeeds
        $this->stockItemCreator->shouldReceive('create')
            ->once()
            ->andReturn([$generatedSku, $stockItemId]);

        // ShopWired fails with specific exception
        $originalException = new ExternalServiceUnavailableException('ShopWired', 120);
        $this->shopwiredUpdateClient->shouldReceive('update')
            ->once()
            ->andThrow($originalException);

        // Rollback succeeds
        $this->inventoryUpdateClient->shouldReceive('deleteInventoryItem')
            ->once();

        // Logger records successful rollback
        $this->logger->shouldReceive('info')
            ->once()
            ->with(
                'Rolled back Linnworks item after ShopWired failure',
                Mockery::on(static fn($ctx) => $ctx['stock_item_id'] === '550e8400-e29b-41d4-a716-446655440012'
                        && $ctx['variation_id'] === 33333),
            );

        try {
            $this->service->generate($params, $variationId);
            $this->fail('Expected exception was not thrown');
        } catch (ExternalServiceUnavailableException $e) {
            // Verify it's the original exception, not a new one
            $this->assertSame($originalException, $e);
            $this->assertSame(120, $e->retryAfter);
        }
    }

    #[Test]
    public function it_logs_critical_when_rollback_fails_after_shopwired_failure(): void
    {
        $params = $this->createParams();
        $variationId = 44444;
        $generatedSku = Sku::fromTrusted('DOUBLE-FAIL');
        $stockItemId = Guid::fromTrusted('550e8400-e29b-41d4-a716-446655440013');

        // Linnworks succeeds
        $this->stockItemCreator->shouldReceive('create')
            ->once()
            ->andReturn([$generatedSku, $stockItemId]);

        // ShopWired fails
        $this->shopwiredUpdateClient->shouldReceive('update')
            ->once()
            ->andThrow(new InvalidApiRequestException('ShopWired', 'Validation error'));

        // Rollback also fails
        $this->inventoryUpdateClient->shouldReceive('deleteInventoryItem')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Linnworks'));

        // Critical log for orphaned item
        $this->logger->shouldReceive('critical')
            ->once()
            ->with(
                'Failed to rollback Linnworks item - manual cleanup required',
                Mockery::on(static fn($ctx) => $ctx['stock_item_id'] === '550e8400-e29b-41d4-a716-446655440013'
                        && $ctx['variation_id'] === 44444),
            );

        // Original ShopWired exception still thrown (not rollback exception)
        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('Validation error');

        $this->service->generate($params, $variationId);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures & Helpers
    |--------------------------------------------------------------------------
    */

    private function createParams(): CreateStockItemParams
    {
        return new CreateStockItemParams(
            categoryId: Guid::fromTrusted('550e8400-e29b-41d4-a716-446655440001'),
            title: 'Test Variation - Red Large',
            retailPrice: Money::inclusive(49.99),
            taxRate: TaxRate::standard(),
            supplierId: Guid::fromTrusted('550e8400-e29b-41d4-a716-446655440002'),
            purchasePrice: Money::exclusive(25.00),
            barcode: null,
            mpn: null,
            supplierCode: null,
            extendedProperties: ['ShopID' => '98765'],
            imageUrl: null,
        );
    }
}
