<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers;

use App\Application\Contracts\Linnworks\InventoryFieldUpdateClientInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
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

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldUpdateClient = Mockery::mock(InventoryFieldUpdateClientInterface::class);
        $this->stockItemRepository = Mockery::mock(StockItemRepositoryInterface::class);

        $this->app->instance(InventoryFieldUpdateClientInterface::class, $this->fieldUpdateClient);
        $this->app->instance(StockItemRepositoryInterface::class, $this->stockItemRepository);
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
            'items' => [['sku' => 'TEST-SKU', 'jit' => true]],
        ]);

        $response->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | Happy Paths — 204 No Content
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_204_when_updating_jit_only(): void
    {
        $this->fieldUpdateClient->shouldReceive('updateFields')->once();
        $this->stockItemRepository->shouldReceive('updateInventoryFieldsBySku')->once()->andReturn(1);

        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [['sku' => 'ABC-123', 'jit' => true]],
        ]);

        $response->assertStatus(204);
    }

    #[Test]
    public function it_returns_204_when_updating_minimum_level_only(): void
    {
        $this->fieldUpdateClient->shouldReceive('updateFields')->once();
        $this->stockItemRepository->shouldReceive('updateInventoryFieldsBySku')->once()->andReturn(1);

        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [['sku' => 'ABC-123', 'minimum_level' => 5]],
        ]);

        $response->assertStatus(204);
    }

    #[Test]
    public function it_returns_204_when_updating_both_fields(): void
    {
        $this->fieldUpdateClient->shouldReceive('updateFields')->once();
        $this->stockItemRepository->shouldReceive('updateInventoryFieldsBySku')->once()->andReturn(1);

        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [['sku' => 'ABC-123', 'jit' => false, 'minimum_level' => 10]],
        ]);

        $response->assertStatus(204);
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
            'items' => [['jit' => true]],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_422_when_both_jit_and_minimum_level_are_absent(): void
    {
        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [['sku' => 'ABC-123']],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_422_when_jit_is_not_a_boolean(): void
    {
        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [['sku' => 'ABC-123', 'jit' => 'not-a-bool']],
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

    /*
    |--------------------------------------------------------------------------
    | Error Cases
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_404_when_linnworks_sku_not_found(): void
    {
        $this->fieldUpdateClient->shouldReceive('updateFields')
            ->once()
            ->andThrow(new ResourceNotFoundException('Linnworks', 'StockItem', 'NOT-FOUND'));

        $response = $this->asApprovedUser()->putJson(self::ENDPOINT, [
            'items' => [['sku' => 'NOT-FOUND', 'jit' => true]],
        ]);

        $response->assertStatus(404);
    }
}
