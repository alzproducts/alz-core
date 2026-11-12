<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\ProductListFreshness;

use App\Application\Contracts\Linnworks\StockDashboardsClientInterface;
use App\Application\Contracts\Shopwired\StockClientInterface;
use App\Application\Inventory\UseCases\SyncFullStockToShopwiredUseCase;
use App\Application\Results\StockUpdateResult;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/products reflects the full stock push's raw-SQL write to the local ShopWired stock mirror
 * on the next request. The variation has no stock item, so its listed stock is the mirror's value.
 */
#[CoversNothing]
final class StockMirrorFreshnessTest extends ProductListFreshnessTestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $productId = $this->insertProduct(0);
        $this->insertVariation($productId, 0, 0, ['stock' => 2]);
    }

    #[Test]
    public function full_stock_push_is_listed_on_the_next_request(): void
    {
        [$before, $fingerprint] = $this->warm(0);
        self::assertSame(2, self::listedVariation($before, 0)['stock']);
        $level = new ItemStockLevel(Sku::fromTrusted(self::sku('V0')), 12);
        $this->mock(StockDashboardsClientInterface::class, static function (MockInterface $mock) use ($level): void {
            $mock->expects('getAllStockLevels')->andReturn([$level]);
        });
        $this->mock(StockClientInterface::class, static function (MockInterface $mock) use ($level): void {
            $mock->expects('updateStockQuantity')->with([$level])->andReturn(new StockUpdateResult([$level]));
        });

        $this->app->make(SyncFullStockToShopwiredUseCase::class)->execute();

        self::assertSame(12, self::listedVariation($this->listedProduct(0), 0)['stock']);
        self::assertNotSame($fingerprint, $this->fragmentFingerprint(0));
    }

    #[Override]
    protected static function idBase(): int
    {
        return 2147480700;
    }

    #[Override]
    protected static function skuPrefix(): string
    {
        return 'FRESH-STK-';
    }
}
