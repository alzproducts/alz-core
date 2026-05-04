<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers;

use App\Application\Contracts\Linnworks\InventoryFieldUpdateClientInterface;
use App\Application\Contracts\Linnworks\LinnworksSyncDispatcherInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\ValueObjects\Guid;
use App\Presentation\Http\Api\Controllers\ProductInventoryUpdateController;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\TestCase;

#[CoversClass(ProductInventoryUpdateController::class)]
final class ProductInventoryUpdateControllerTest extends TestCase
{
    use AuthenticatesAsApprovedUser;

    private const string ENDPOINT = '/api/products/inventory';

    private InventoryFieldUpdateClientInterface&MockInterface $fieldUpdateClient;

    private StockItemRepositoryInterface&MockInterface $stockItemRepository;

    private LinnworksSyncDispatcherInterface&MockInterface $syncDispatcher;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldUpdateClient = Mockery::mock(InventoryFieldUpdateClientInterface::class);
        $this->stockItemRepository = Mockery::mock(StockItemRepositoryInterface::class);
        $this->syncDispatcher = Mockery::mock(LinnworksSyncDispatcherInterface::class);

        $this->app->instance(InventoryFieldUpdateClientInterface::class, $this->fieldUpdateClient);
        $this->app->instance(StockItemRepositoryInterface::class, $this->stockItemRepository);
        $this->app->instance(LinnworksSyncDispatcherInterface::class, $this->syncDispatcher);
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function unauthenticated_request_returns_401(): void
    {
        $response = $this->putJson(self::ENDPOINT, [
            'items' => [['sku' => 'TEST-SKU', 'minimum_level' => 5]],
        ]);

        $response->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | Happy Paths — 200 with body
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_200_with_body_when_updating_minimum_level(): void
    {
        $this->fieldUpdateClient->shouldReceive('updateFields')->once();
        $this->stockItemRepository->shouldReceive('bulkUpdateInventoryFieldsBySkus')->once()->andReturn(1);

        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [['sku' => 'ABC-123', 'minimum_level' => 5]],
        ]);

        $response->assertStatus(200);
        $response->assertExactJson([
            'total' => 1,
            'succeeded' => 1,
            'failures' => [],
        ]);
    }

    #[Test]
    public function it_returns_200_with_aggregate_counts_for_a_full_batch(): void
    {
        $this->fieldUpdateClient->shouldReceive('updateFields')->times(3);
        $this->stockItemRepository->shouldReceive('bulkUpdateInventoryFieldsBySkus')
            ->once()
            ->andReturn(3);

        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [
                ['sku' => 'ABC-1', 'minimum_level' => 1],
                ['sku' => 'ABC-2', 'minimum_level' => 5],
                ['sku' => 'ABC-3', 'minimum_level' => 10],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertExactJson([
            'total' => 3,
            'succeeded' => 3,
            'failures' => [],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation — 422
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_422_when_body_is_empty(): void
    {
        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, []);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_422_when_items_array_is_empty(): void
    {
        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, ['items' => []]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_422_when_sku_is_missing(): void
    {
        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [['minimum_level' => 5]],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_422_when_minimum_level_is_absent(): void
    {
        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [['sku' => 'ABC-123']],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_422_when_minimum_level_is_negative(): void
    {
        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [['sku' => 'ABC-123', 'minimum_level' => -1]],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_422_when_minimum_level_is_not_an_integer(): void
    {
        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [['sku' => 'ABC-123', 'minimum_level' => 1.5]],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_422_when_more_than_25_items_submitted(): void
    {
        $items = [];
        for ($i = 1; $i <= 26; $i++) {
            $items[] = ['sku' => "SKU-{$i}", 'minimum_level' => 5];
        }

        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, ['items' => $items]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_422_when_a_sku_appears_more_than_once(): void
    {
        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [
                ['sku' => 'DUPLICATE', 'minimum_level' => 3],
                ['sku' => 'DUPLICATE', 'minimum_level' => 5],
            ],
        ]);

        $response->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Partial Success — per-item failures surface in body, not status
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_200_with_failure_in_body_when_linnworks_sku_not_found(): void
    {
        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->once()
            ->andThrow(new ResourceNotFoundException('Linnworks', 'StockItem', 'NOT-FOUND'));

        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [['sku' => 'NOT-FOUND', 'minimum_level' => 5]],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('succeeded', 0);
        $response->assertJsonPath('failures.0.sku', 'NOT-FOUND');
        $response->assertJsonStructure(['failures' => [['sku', 'error']]]);
    }

    #[Test]
    public function it_returns_200_with_partial_success_when_one_sku_in_a_batch_fails(): void
    {
        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->ordered()
            ->once()
            ->withArgs(static fn($identifier): bool => $identifier->value === 'GOOD-1');
        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->ordered()
            ->once()
            ->andThrow(new ResourceNotFoundException('Linnworks', 'StockItem', 'BAD-2'));
        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->ordered()
            ->once()
            ->withArgs(static fn($identifier): bool => $identifier->value === 'GOOD-3');

        // Bulk write only happens for the two succeeded SKUs.
        $this->stockItemRepository->shouldReceive('bulkUpdateInventoryFieldsBySkus')
            ->once()
            ->withArgs(
                static fn(array $updatesBySku): bool
                => \array_keys($updatesBySku) === ['GOOD-1', 'GOOD-3'],
            )
            ->andReturn(2);

        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [
                ['sku' => 'GOOD-1', 'minimum_level' => 1],
                ['sku' => 'BAD-2', 'minimum_level' => 2],
                ['sku' => 'GOOD-3', 'minimum_level' => 5],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('total', 3);
        $response->assertJsonPath('succeeded', 2);
        $response->assertJsonPath('failures.0.sku', 'BAD-2');
    }

    #[Test]
    public function it_returns_200_when_transient_api_failure_occurs_per_sku(): void
    {
        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Linnworks'));

        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [['sku' => 'TIMEOUT-SKU', 'minimum_level' => 5]],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('succeeded', 0);
        $response->assertJsonPath('failures.0.sku', 'TIMEOUT-SKU');
    }

    #[Test]
    public function it_dispatches_reconciliation_sync_when_local_db_write_fails(): void
    {
        $this->fieldUpdateClient->shouldReceive('updateFields')->once();

        $this->stockItemRepository->shouldReceive('bulkUpdateInventoryFieldsBySkus')
            ->once()
            ->andThrow(new DatabaseOperationFailedException('bulkUpdate', 'connection lost'));

        $stockItemId = new Guid('11111111-1111-4111-8111-111111111111');
        $this->stockItemRepository->shouldReceive('resolveStockItemIdsBySkus')
            ->once()
            ->andReturn(['ABC-123' => $stockItemId]);

        $this->syncDispatcher->shouldReceive('dispatchStockItemSync')
            ->once()
            ->with(Mockery::on(static fn(Guid $g): bool => $g->value === $stockItemId->value));

        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [['sku' => 'ABC-123', 'minimum_level' => 5]],
        ]);

        // DB failure demotes the API-succeeded item to a permanent failure in the response body.
        $response->assertStatus(200);
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('succeeded', 0);
        $response->assertJsonPath('failures.0.sku', 'ABC-123');
    }
}
