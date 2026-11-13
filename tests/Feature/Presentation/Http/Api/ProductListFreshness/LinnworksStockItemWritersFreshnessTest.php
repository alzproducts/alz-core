<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\ProductListFreshness;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryFieldUpdateClientInterface;
use App\Application\Linnworks\UseCases\SyncAllStockItemsUseCase;
use App\Application\Linnworks\UseCases\SyncStockItemUseCase;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\ValueObjects\Guid;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/products reflects each per-item Linnworks stock-item writer's commit on the next request.
 */
#[CoversNothing]
final class LinnworksStockItemWritersFreshnessTest extends ProductListFreshnessTestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->insertProduct(0);
        $this->insertStockItem(0, self::sku('P0'), ['quantity' => 3, 'available' => 3, 'minimum_level' => 1]);
        $this->insertStockItemSupplier(0, 1, '10.00');
    }

    #[Test]
    public function stock_item_sync_is_listed_on_the_next_request(): void
    {
        [$before, $fingerprint] = $this->warm(0);
        self::assertIsArray($before['stock']);
        self::assertSame(3, $before['stock']['quantity']);
        self::assertSame(10, $before['cost_price']);

        $this->fakeStockItemFull(self::stockItemFull(0, self::sku('P0'), 8, [
            self::stockItemSupplier(0, 1, 10.0, false),
            self::stockItemSupplier(0, 2, 14.5),
        ]));

        $this->app->make(SyncStockItemUseCase::class)->execute(new Guid(self::guid(0)));

        $product = $this->listedProduct(0);
        self::assertIsArray($product['stock']);
        self::assertSame(8, $product['stock']['quantity']);
        self::assertSame(14.5, $product['cost_price']);
        self::assertIsArray($product['default_supplier']);
        self::assertSame(self::supplierName(2), $product['default_supplier']['supplier_name']);
        self::assertNotSame($fingerprint, $this->fragmentFingerprint(0));
    }

    #[Test]
    public function stock_item_number_change_moves_the_stock_onto_the_product_on_the_next_request(): void
    {
        $this->insertStockItem(1, self::sku('OTHER'), ['quantity' => 6, 'available' => 6]);
        $this->deleteStockItem(0);
        [$before] = $this->warm(0);
        self::assertIsArray($before['stock']);
        self::assertNull($before['stock']['quantity']);

        $this->fakeStockItemFull(self::stockItemFull(1, self::sku('P0'), 6));

        $this->app->make(SyncStockItemUseCase::class)->execute(new Guid(self::guid(1)));

        $product = $this->listedProduct(0);
        self::assertIsArray($product['stock']);
        self::assertSame(6, $product['stock']['quantity']);
    }

    #[Test]
    public function full_stock_item_sync_is_listed_on_the_next_request(): void
    {
        $this->warm(0);
        $this->mock(InventoryClientInterface::class, static function (MockInterface $mock): void {
            $mock->expects('iterateStockItemBatches')->andReturnUsing(static function (): Generator {
                yield 1 => [self::stockItemFull(0, self::sku('P0'), 11, [self::stockItemSupplier(0, 1, 12.25)])];
            });
        });

        $this->app->make(SyncAllStockItemsUseCase::class)->execute();

        $product = $this->listedProduct(0);
        self::assertIsArray($product['stock']);
        self::assertSame(11, $product['stock']['quantity']);
        self::assertSame(12.25, $product['cost_price']);
    }

    #[Test]
    public function inventory_route_minimum_level_is_listed_on_the_next_request(): void
    {
        [$before] = $this->warm(0);
        self::assertIsArray($before['inventory']);
        self::assertSame(1, $before['inventory']['minimum_level']);
        $this->mock(InventoryFieldUpdateClientInterface::class, static function (MockInterface $mock): void {
            $mock->expects('updateFields')->once();
        });

        $this->putJson('/api/products/inventory', ['items' => [['sku' => self::sku('P0'), 'minimum_level' => 4]]])
            ->assertSuccessful();

        $product = $this->listedProduct(0);
        self::assertIsArray($product['inventory']);
        self::assertSame(4, $product['inventory']['minimum_level']);
    }

    private function fakeStockItemFull(StockItemFull $stockItem): void
    {
        $this->mock(InventoryClientInterface::class, static function (MockInterface $mock) use ($stockItem): void {
            $mock->expects('getStockItemFull')->andReturn($stockItem);
        });
    }

    private function deleteStockItem(int $n): void
    {
        DB::table('linnworks.stock_item_suppliers')->where('stock_item_id', self::guid($n))->delete();
        DB::table('linnworks.stock_items')->where('stock_item_id', self::guid($n))->delete();
    }

    #[Override]
    protected static function idBase(): int
    {
        return 2147480200;
    }

    #[Override]
    protected static function skuPrefix(): string
    {
        return 'FRESH-LW-';
    }
}
