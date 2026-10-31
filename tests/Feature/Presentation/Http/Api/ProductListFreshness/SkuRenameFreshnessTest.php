<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\ProductListFreshness;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\Shopwired\BasicProductUpdateClientInterface;
use App\Application\Inventory\UseCases\UpdateSkuUseCase;
use App\Application\Linnworks\UseCases\SyncStockItemUseCase;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Inventory\Commands\UpdateSkuCommand;
use App\Domain\Inventory\Enums\SkuUpdateReason;
use App\Domain\ValueObjects\Guid;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * A SKU rename writes only its audit row locally; the list sees it through the ShopWired product
 * webhook (new `sku`) and the Linnworks stock-item sync (new `item_number`), which re-join the
 * stock to the product. Each step is listed on the request after it.
 */
#[CoversNothing]
final class SkuRenameFreshnessTest extends ProductListFreshnessTestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->insertProduct(0);
        $this->insertStockItem(0, self::sku('P0'), ['quantity' => 3, 'available' => 3]);
    }

    #[Test]
    public function sku_rename_is_listed_after_each_local_write_it_causes(): void
    {
        [$before] = $this->warm(0);
        self::assertSame(self::sku('P0'), $before['sku']);
        $this->mock(InventoryUpdateClientInterface::class, static function (MockInterface $mock): void {
            $mock->expects('updateSku')->once();
        });
        $this->mock(BasicProductUpdateClientInterface::class, static function (MockInterface $mock): void {
            $mock->expects('update')->once();
        });
        $this->mock(InventoryClientInterface::class, static function (MockInterface $mock): void {
            $mock->expects('getStockItemFull')->andReturn(self::stockItemFull(0, self::sku('P0R'), 3));
        });

        $this->app->make(UpdateSkuUseCase::class)->execute(
            UpdateSkuCommand::provided(self::sku('P0'), Sku::fromTrusted(self::sku('P0R')), SkuUpdateReason::FixSkuMismatch),
        );

        $this->postWebhook('products', 'product.updated', self::productExternalId(0), [
            'object' => self::productPayload(0, ['sku' => self::sku('P0R')]),
        ]);

        $renamed = $this->listedProduct(0);
        self::assertSame(self::sku('P0R'), $renamed['sku']);
        self::assertIsArray($renamed['stock']);
        self::assertNull($renamed['stock']['quantity']);

        $this->app->make(SyncStockItemUseCase::class)->execute(new Guid(self::guid(0)));

        $relinked = $this->listedProduct(0);
        self::assertIsArray($relinked['stock']);
        self::assertSame(3, $relinked['stock']['quantity']);
    }

    #[Override]
    protected static function idBase(): int
    {
        return 2147480600;
    }

    #[Override]
    protected static function skuPrefix(): string
    {
        return 'FRESH-RN-';
    }
}
