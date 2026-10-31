<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\ProductListFreshness;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\Shopwired\PriceUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductClientInterface;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Inventory\ValueObjects\StockItemSupplierStat;
use App\Domain\Inventory\ValueObjects\Supplier;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Jobs\Operations\RecordPricePeriodJob;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/products reflects the cost-price, RRP and price-period writers on the next request.
 */
#[CoversNothing]
final class PricingWritersFreshnessTest extends ProductListFreshnessTestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->mock(PriceUpdateClientInterface::class);
        $this->mock(ProductClientInterface::class);
        $this->insertProduct(0);
        $this->insertStockItem(0, self::sku('P0'), ['quantity' => 3, 'available' => 3]);
        $this->insertStockItemSupplier(0, 1, '10.00');
    }

    #[Test]
    public function cost_price_route_and_its_change_log_are_listed_on_the_next_request(): void
    {
        [$before, $fingerprint] = $this->warm(0);
        self::assertSame(10, $before['cost_price']);
        self::assertNull($before['cost_price_last_updated_at']);
        $this->mock(InventoryClientInterface::class, static function (MockInterface $mock): void {
            $mock->expects('resolveStockItemIds')->andReturn([self::sku('P0') => new Guid(self::guid(0))]);
            $mock->expects('getSuppliers')->andReturn([self::linnworksSupplier(1)]);
            $mock->expects('getStockSupplierStatsBulk')->andReturn([self::guid(0) => [self::supplierStat(0, 1, 10.0)]]);
        });
        $this->mock(InventoryUpdateClientInterface::class, static function (MockInterface $mock): void {
            $mock->expects('updateStockSupplierStats')->once();
        });

        $this->putJson('/api/products/cost-prices', [
            'supplierName' => self::supplierName(1),
            'items' => [['sku' => self::sku('P0'), 'costPrice' => 13.75]],
        ])->assertSuccessful();

        $product = $this->listedProduct(0);
        self::assertSame(13.75, $product['cost_price']);
        self::assertIsString($product['cost_price_last_updated_at']);
        self::assertNotSame($fingerprint, $this->fragmentFingerprint(0));
    }

    #[Test]
    public function rrp_route_is_listed_on_the_next_request(): void
    {
        [$before] = $this->warm(0);
        self::assertNull($before['rrp']);

        $this->putJson('/api/products/' . self::productExternalId(0) . '/prices', [
            'sku_updates' => [['sku' => self::sku('P0'), 'rrp' => 79.99]],
        ])->assertSuccessful();

        self::assertSame(79.99, $this->listedProduct(0)['rrp']);
    }

    #[Test]
    public function price_period_record_is_listed_on_the_next_request(): void
    {
        [$before] = $this->warm(0);
        self::assertNull($before['price_last_updated_at']);

        $this->app->call([
            new RecordPricePeriodJob(Sku::fromTrusted(self::sku('P0')), ProductRetailPricing::forMainProduct(50.0, null)),
            'handle',
        ]);

        self::assertIsString($this->listedProduct(0)['price_last_updated_at']);
    }

    private static function linnworksSupplier(int $n): Supplier
    {
        return new Supplier(
            self::guid(900 + $n),
            self::supplierName($n),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
        );
    }

    private static function supplierStat(int $stockItemN, int $supplierN, float $purchasePrice): StockItemSupplierStat
    {
        return new StockItemSupplierStat(
            stockItemId: new Guid(self::guid($stockItemN)),
            stockItemIntId: null,
            supplierId: new Guid(self::guid(900 + $supplierN)),
            supplierName: self::supplierName($supplierN),
            code: null,
            supplierBarcode: null,
            purchasePrice: Money::exclusive($purchasePrice),
            isDefault: true,
            leadTime: null,
            supplierCurrency: null,
            minPrice: null,
            maxPrice: null,
            averagePrice: null,
            averageLeadTime: null,
            supplierMinOrderQty: null,
            supplierPackSize: null,
        );
    }

    #[Override]
    protected static function idBase(): int
    {
        return 2147480400;
    }

    #[Override]
    protected static function skuPrefix(): string
    {
        return 'FRESH-PRC-';
    }
}
