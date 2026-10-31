<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\ProductListFreshness;

use App\Application\Contracts\Linnworks\StockDashboardsClientInterface;
use App\Application\Linnworks\UseCases\SyncArchivedStockItemsUseCase;
use App\Application\Linnworks\UseCases\SyncCompositeStockItemFlagsUseCase;
use App\Domain\ValueObjects\Guid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/products reflects the bulk Linnworks archive and composite-flag jobs on the next request.
 */
#[CoversNothing]
final class LinnworksBulkStockItemWritersFreshnessTest extends ProductListFreshnessTestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->insertProduct(0);
        $this->insertStockItem(0, self::sku('P0'), ['quantity' => 3, 'available' => 3, 'is_composite' => false]);
    }

    #[Test]
    public function archived_stock_item_sync_is_listed_on_the_next_request(): void
    {
        [$before] = $this->warm(0);
        self::assertIsArray($before['stock']);
        self::assertSame(3, $before['stock']['quantity']);
        $this->mock(StockDashboardsClientInterface::class, static function (MockInterface $mock): void {
            $mock->expects('getArchivedStockItemsFull')->andReturn([self::stockItemFull(0, self::sku('P0'), 5)]);
        });

        $this->app->make(SyncArchivedStockItemsUseCase::class)->execute();

        $product = $this->listedProduct(0);
        self::assertIsArray($product['stock']);
        self::assertSame(5, $product['stock']['quantity']);
    }

    #[Test]
    public function composite_flag_sync_is_listed_on_the_next_request(): void
    {
        [$before, $fingerprint] = $this->warm(0);
        self::assertFalse($before['is_composite']);
        $this->mock(StockDashboardsClientInterface::class, static function (MockInterface $mock): void {
            // intentional: the sync resets every row not returned, so keep each existing composite flag as it is
            $mock->expects('getCompositeStockItemIds')->andReturnUsing(static fn(): array => \array_map(
                static fn(string $id): Guid => new Guid($id),
                [...DB::table('linnworks.stock_items')->where('is_composite', true)->pluck('stock_item_id')->all(), self::guid(0)],
            ));
        });

        $this->app->make(SyncCompositeStockItemFlagsUseCase::class)->execute();

        self::assertTrue($this->listedProduct(0)['is_composite']);
        self::assertNotSame($fingerprint, $this->fragmentFingerprint(0));
    }

    #[Override]
    protected static function idBase(): int
    {
        return 2147480300;
    }

    #[Override]
    protected static function skuPrefix(): string
    {
        return 'FRESH-LWB-';
    }
}
