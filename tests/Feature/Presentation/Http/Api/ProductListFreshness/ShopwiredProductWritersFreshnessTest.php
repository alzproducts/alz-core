<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\ProductListFreshness;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Shopwired\CustomFieldClientInterface;
use App\Application\Contracts\Shopwired\ProductClientInterface;
use App\Application\Shopwired\UseCases\ReconcileProductsUseCase;
use App\Application\Shopwired\UseCases\SyncCustomFieldsUseCase;
use App\Application\Shopwired\UseCases\SyncProductsUseCase;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\ValueObjects\Guid;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/products reflects each ShopWired product writer's commit on the next request.
 */
#[CoversNothing]
final class ShopwiredProductWritersFreshnessTest extends ProductListFreshnessTestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $productId = $this->insertProduct(0);
        $this->insertVariation($productId, 0, 0);
    }

    #[Test]
    public function product_webhook_upsert_is_listed_on_the_next_request(): void
    {
        [, $fingerprint] = $this->warm(0);

        $this->postWebhook('products', 'product.updated', self::productExternalId(0), [
            'object' => self::productPayload(0, ['title' => 'Freshness product renamed', 'price' => 61.25]),
        ]);

        $product = $this->listedProduct(0);
        self::assertSame('Freshness product renamed', $product['title']);
        self::assertSame(61.25, $product['price']);
        self::assertNotSame($fingerprint, $this->fragmentFingerprint(0));
    }

    #[Test]
    public function product_webhook_delete_drops_the_product_on_the_next_request(): void
    {
        $this->warm(0);

        $this->postWebhook('products', 'product.deleted', self::productExternalId(0), []);

        self::assertArrayNotHasKey(self::productExternalId(0), $this->listProducts());
    }

    #[Test]
    public function product_webhook_stock_change_is_listed_on_the_next_request(): void
    {
        [$before] = $this->warm(0);
        self::assertSame(0, self::listedVariation($before, 0)['stock']);

        $this->postWebhook('products', 'product.stock_changed', self::productExternalId(0), [
            'sku' => self::sku('V0'),
            'is_variation' => true,
            'new_quantity' => 7,
        ]);

        self::assertSame(7, self::listedVariation($this->listedProduct(0), 0)['stock']);
    }

    #[Test]
    public function product_refresh_route_is_listed_on_the_next_request(): void
    {
        $this->warm(0);
        $this->mock(ProductClientInterface::class, static function (MockInterface $mock): void {
            $mock->expects('getProductById')->with(self::productExternalId(0))
                ->andReturn(self::domainProduct(0, 72.5, [self::domainVariation(0, 0, 72.5)]));
        });
        $this->mock(InventoryClientInterface::class, static function (MockInterface $mock): void {
            $mock->expects('resolveStockItemIds')->andReturn([self::sku('P0') => new Guid(self::guid(0))]);
            $mock->expects('getStockItemsFullByIds')->andReturn([self::stockItemFull(0, self::sku('P0'), 9)]);
        });

        $this->postJson('/api/products/' . self::productExternalId(0) . '/refresh')->assertSuccessful();

        $product = $this->listedProduct(0);
        self::assertSame(72.5, $product['price']);
        self::assertIsArray($product['stock']);
        self::assertSame(9, $product['stock']['quantity']);
    }

    #[Test]
    public function daily_sync_variation_replace_is_listed_on_the_next_request(): void
    {
        $this->warm(0);
        $this->mock(ProductClientInterface::class, static function (MockInterface $mock): void {
            $mock->expects('iterateProductBatches')->andReturnUsing(static function (): Generator {
                yield 1 => [self::domainProduct(0, 50.0, [self::domainVariation(0, 1, 33.75)])];
            });
        });

        $this->app->make(SyncProductsUseCase::class)->execute();

        $product = $this->listedProduct(0);
        self::assertIsArray($product['variations']);
        self::assertCount(1, $product['variations']);
        self::assertSame(33.75, self::listedVariation($product, 1)['price']);
    }

    #[Test]
    public function reconcile_delete_drops_the_orphan_on_the_next_request(): void
    {
        $this->insertProduct(1);
        self::assertArrayHasKey(self::productExternalId(1), $this->listProducts());
        self::assertNotNull($this->fragmentFingerprint(1));

        $this->mock(ProductClientInterface::class, static function (MockInterface $mock): void {
            // intentional: every local id plus the whole freshness range, so products other tests insert meanwhile are never orphans
            $mock->expects('getAllProductIds')->andReturnUsing(static fn(): array => \array_values(\array_diff(
                [...DB::table('shopwired.products')->pluck('external_id')->all(), ...\range(2147480000, 2147480999)],
                [self::productExternalId(1)],
            )));
        });

        $result = $this->app->make(ReconcileProductsUseCase::class)->execute();

        self::assertSame([self::productExternalId(1)], $result->orphanIds);
        $products = $this->listProducts();
        self::assertArrayNotHasKey(self::productExternalId(1), $products);
        self::assertArrayHasKey(self::productExternalId(0), $products);
    }

    /**
     * No listed field exposes an arbitrary definition, and the ones that type listed fields
     * (`discontinued`, `free_delivery`) are real rows on the shared database, so this asserts the
     * invalidation itself: the fragment built under the old definitions is not served again.
     */
    #[Test]
    public function custom_field_definition_sync_rebuilds_the_fragment_on_the_next_request(): void
    {
        [$before, $fingerprint] = $this->warm(0);
        $this->mock(CustomFieldClientInterface::class, static function (MockInterface $mock): void {
            $mock->expects('listAll')->andReturn([new CustomFieldDefinition(
                id: self::productExternalId(80),
                name: 'fresh_swp_probe',
                type: CustomFieldType::Text,
                label: null,
                itemType: CustomFieldItemType::Page,
                sortOrder: null,
                allowedValues: null,
            )]);
        });

        $this->app->make(SyncCustomFieldsUseCase::class)->execute();

        self::assertSame($before['title'], $this->listedProduct(0)['title']);
        self::assertNotSame($fingerprint, $this->fragmentFingerprint(0));
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::table('shopwired.custom_field_definitions')->where('external_id', self::productExternalId(80))->delete();

        parent::tearDown();
    }

    #[Override]
    protected static function idBase(): int
    {
        return 2147480000;
    }

    #[Override]
    protected static function skuPrefix(): string
    {
        return 'FRESH-SWP-';
    }
}
