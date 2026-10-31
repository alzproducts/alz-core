<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\ProductListFreshness;

use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldValueList;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Inventory\ValueObjects\Dimensions;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\Inventory\ValueObjects\StockItemSupplier;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Override;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\TestCase;

/**
 * Read-your-writes harness for GET /api/products: fixtures and writes commit on `pgsql`, the list
 * is read over HTTP on {@see self::READER_CONNECTION} (a second session), so a stale fragment or
 * snapshot would show.
 *
 * Each subclass owns 100 external ids from {@see self::idBase()} and a SKU prefix; setUp and
 * tearDown delete only those, so classes run side by side on the shared database.
 */
abstract class ProductListFreshnessTestCase extends TestCase
{
    use AuthenticatesAsApprovedUser;

    protected const string LIST_INCLUDES = 'variations,stock,inventory';

    /** A copy of `pgsql` under another name: its own PDO session, as a separate Octane worker would have. */
    protected const string READER_CONNECTION = 'pgsql_list_reader';

    private const string WEBHOOK_SECRET = 'product-list-freshness-secret';

    private static int $webhookSequence = 0;

    /** @var list<string> product uuids whose fragments outlive their product row */
    private array $trackedProductIds = [];

    abstract protected static function idBase(): int;

    abstract protected static function skuPrefix(): string;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // intentional: no DB trait — each write must commit so the list's own session can see it
        DB::setDefaultConnection('pgsql');
        $this->cleanUp();
        $this->asApprovedUser();
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::setDefaultConnection('pgsql');
        $this->cleanUp();

        parent::tearDown();
    }

    protected static function productExternalId(int $n): int
    {
        return static::idBase() + $n;
    }

    protected static function variationExternalId(int $n): int
    {
        return static::idBase() + 30 + $n;
    }

    protected static function categoryExternalId(int $n = 0): int
    {
        return static::idBase() + 90 + $n;
    }

    protected static function sku(string $suffix): string
    {
        return static::skuPrefix() . $suffix;
    }

    protected static function guid(int $n): string
    {
        return \sprintf('%08x-0000-4000-8000-%012x', static::idBase(), $n);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertProduct(int $n, array $overrides = []): string
    {
        $now = \now();
        $id = DB::table('shopwired.products')->insertGetId([
            'external_id' => self::productExternalId($n),
            'sku' => self::sku("P{$n}"),
            'title' => "Freshness product {$n}",
            'slug' => \mb_strtolower(static::skuPrefix()) . "p{$n}",
            'url' => '/' . \mb_strtolower(static::skuPrefix()) . "p{$n}",
            'price' => '50.00',
            'stock' => 0,
            'is_active' => true,
            'vat_exclusive' => false,
            'category_ids' => \json_encode([self::categoryExternalId()]),
            'shopwired_created_at' => $now,
            'shopwired_updated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            ...$overrides,
        ]);
        \assert(\is_string($id));
        $this->trackedProductIds[] = $id;

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertVariation(string $productId, int $productN, int $n, array $overrides = []): void
    {
        $now = \now();
        DB::table('shopwired.product_variations')->insert([
            'product_id' => $productId,
            'product_external_id' => self::productExternalId($productN),
            'external_id' => self::variationExternalId($n),
            'sku' => self::sku("V{$n}"),
            'price' => '50.00',
            'stock' => 0,
            'options' => \json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
            ...$overrides,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertCategory(int $n, array $overrides = []): void
    {
        $now = \now();
        DB::table('shopwired.categories')->insert([
            'external_id' => self::categoryExternalId($n),
            'shopwired_created_at' => $now,
            'title' => "Freshness category {$n}",
            'slug' => \mb_strtolower(static::skuPrefix()) . "c{$n}",
            'url' => '/' . \mb_strtolower(static::skuPrefix()) . "c{$n}",
            'active' => true,
            'featured' => false,
            'trade_only' => false,
            'sort_order' => 0,
            'meta_no_index' => false,
            'parent_ids' => \json_encode([]),
            'custom_fields' => \json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
            ...$overrides,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertStockItem(int $n, string $sku, array $overrides = []): void
    {
        $now = \now();
        DB::table('linnworks.stock_items')->insert([
            'stock_item_id' => self::guid($n),
            'item_number' => $sku,
            'item_title' => "Freshness stock item {$n}",
            'quantity' => 0,
            'available' => 0,
            'in_order' => 0,
            'due' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            ...$overrides,
        ]);
    }

    protected function insertStockItemSupplier(int $stockItemN, int $supplierN, string $purchasePrice, bool $isDefault = true): void
    {
        $now = \now();
        DB::table('linnworks.stock_item_suppliers')->insert([
            'id' => self::guid(500 + ($stockItemN * 10) + $supplierN),
            'stock_item_id' => self::guid($stockItemN),
            'supplier_id' => self::guid(900 + $supplierN),
            'supplier_name' => self::supplierName($supplierN),
            'purchase_price' => $purchasePrice,
            'is_default' => $isDefault,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected static function supplierName(int $n): string
    {
        return static::skuPrefix() . "SUPPLIER-{$n}";
    }

    /**
     * Lists the class's category over HTTP on the `pgsql_rls` session.
     *
     * @return array<int, array<string, mixed>> products keyed by external id
     */
    protected function listProducts(?int $categoryN = 0): array
    {
        if (\config('database.connections.' . self::READER_CONNECTION) === null) {
            \config(['database.connections.' . self::READER_CONNECTION => \config('database.connections.pgsql')]);
        }
        DB::setDefaultConnection(self::READER_CONNECTION);

        try {
            $response = $this->getJson('/api/products?' . \http_build_query([
                'category_id' => self::categoryExternalId($categoryN ?? 0),
                'include' => static::LIST_INCLUDES,
                'per_page' => 100,
            ]));
        } finally {
            DB::setDefaultConnection('pgsql');
        }

        $response->assertOk();
        $data = $response->json('data');
        self::assertIsArray($data);

        $products = [];
        foreach ($data as $product) {
            self::assertIsArray($product);
            self::assertIsInt($product['id']);
            $products[$product['id']] = $product;
        }

        return $products;
    }

    /**
     * @return array<string, mixed>
     */
    protected function listedProduct(int $n, int $categoryN = 0): array
    {
        $products = $this->listProducts($categoryN);
        self::assertArrayHasKey(self::productExternalId($n), $products, 'The product is missing from the list');

        return $products[self::productExternalId($n)];
    }

    /**
     * @param array<string, mixed> $product
     *
     * @return array<string, mixed>
     */
    protected static function listedVariation(array $product, int $n): array
    {
        self::assertIsArray($product['variations']);

        foreach ($product['variations'] as $variation) {
            self::assertIsArray($variation);
            if ($variation['id'] === self::variationExternalId($n)) {
                return $variation;
            }
        }

        self::fail('The variation is missing from the listed product');
    }

    /**
     * Lists once so the product's fragment is stored, and returns that fragment's fingerprint;
     * without a stored fragment a stale read could not happen and the test would prove nothing.
     *
     * @return array{array<string, mixed>, string}
     */
    protected function warm(int $n, int $categoryN = 0): array
    {
        $product = $this->listedProduct($n, $categoryN);
        $fingerprint = $this->fragmentFingerprint($n);
        self::assertNotNull($fingerprint, 'Listing must store the product fragment');

        return [$product, $fingerprint];
    }

    protected function fragmentFingerprint(int $n): ?string
    {
        $fingerprint = DB::table('catalog.product_list_fragments as f')
            ->join('shopwired.products as p', 'p.id', '=', 'f.product_id')
            ->where('p.external_id', self::productExternalId($n))
            ->value('f.fingerprint');

        return \is_string($fingerprint) ? $fingerprint : null;
    }

    protected function productUuid(int $n): string
    {
        $id = DB::table('shopwired.products')->where('external_id', self::productExternalId($n))->value('id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * Posts a signed ShopWired webhook through the real route.
     *
     * @param array<string, mixed> $data
     */
    protected function postWebhook(string $entity, string $topic, int $subjectId, array $data): void
    {
        \config(['shopwired.webhook_secret' => self::WEBHOOK_SECRET]);
        $body = \json_encode([
            'timestamp' => \now()->format(\DATE_ATOM),
            'event' => [
                'id' => self::nextWebhookId(),
                'topic' => $topic,
                'subjectId' => $subjectId,
                'data' => $data,
            ],
        ], \JSON_THROW_ON_ERROR);

        $this->call('POST', "/api/webhooks/shopwired/{$entity}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPWIRED_SIGNATURE' => \hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body)->assertOk();
    }

    /**
     * A ShopWired product webhook/API object for a fixture product, without embeds.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected static function productPayload(int $n, array $overrides = []): array
    {
        $now = \now()->format(\DATE_ATOM);

        return [
            'id' => self::productExternalId($n),
            'sku' => self::sku("P{$n}"),
            'gtin' => null,
            'title' => "Freshness product {$n}",
            'description' => null,
            'slug' => \mb_strtolower(static::skuPrefix()) . "p{$n}",
            'url' => '/' . \mb_strtolower(static::skuPrefix()) . "p{$n}",
            'price' => 50.0,
            'cost_price' => null,
            'sale_price' => null,
            'compare_price' => null,
            'stock' => 0,
            'sort_order' => null,
            'active' => true,
            'vat_exclusive' => false,
            'weight' => null,
            'meta_title' => null,
            'meta_description' => null,
            'created_at' => $now,
            'updated_at' => $now,
            ...$overrides,
        ];
    }

    /**
     * The domain Product a faked ShopWired client returns for a fixture product.
     *
     * @param list<ProductVariation>|null $variations
     */
    protected static function domainProduct(int $n, float $price, ?array $variations = null, ?string $title = null, ?string $sku = null): Product
    {
        $now = new DateTimeImmutable();

        return new Product(
            id: self::productExternalId($n),
            sku: $sku ?? self::sku("P{$n}"),
            gtin: null,
            title: $title ?? "Freshness product {$n}",
            description: null,
            slug: \mb_strtolower(static::skuPrefix()) . "p{$n}",
            url: '/' . \mb_strtolower(static::skuPrefix()) . "p{$n}",
            price: $price,
            costPrice: null,
            salePrice: null,
            comparePrice: null,
            stock: 0,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            weight: null,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [self::categoryExternalId()],
            variations: $variations,
            images: [],
            rawCustomFields: [],
            customFields: CustomFieldValueList::empty(),
            rawFilters: [],
            filters: [],
            sortOrder: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    protected static function domainVariation(int $productN, int $n, float $price, int $stock = 0): ProductVariation
    {
        return new ProductVariation(
            id: self::variationExternalId($n),
            productExternalId: self::productExternalId($productN),
            sku: self::sku("V{$n}"),
            price: $price,
            costPrice: null,
            salePrice: null,
            stock: $stock,
            weight: null,
            gtin: null,
            mpn: null,
            imageIndex: null,
        );
    }

    /**
     * The StockItemFull a faked Linnworks client returns for stock item $n.
     *
     * @param list<StockItemSupplier> $suppliers
     */
    protected static function stockItemFull(int $n, string $sku, int $quantity, array $suppliers = [], bool $isComposite = false): StockItemFull
    {
        return new StockItemFull(
            stockItemId: self::guid($n),
            sku: $sku,
            title: "Freshness stock item {$n}",
            barcode: '',
            quantity: $quantity,
            available: $quantity,
            inOrder: 0,
            due: 0,
            minimumLevel: 0,
            jit: false,
            purchasePrice: 0.0,
            retailPrice: 0.0,
            taxRate: null,
            weight: Weight::zero(),
            dimensions: Dimensions::zero(),
            isComposite: $isComposite,
            categoryId: '00000000-0000-0000-0000-000000000000',
            categoryName: 'Default',
            suppliers: $suppliers,
        );
    }

    protected static function stockItemSupplier(int $stockItemN, int $supplierN, float $purchasePrice, bool $isDefault = true): StockItemSupplier
    {
        return new StockItemSupplier(
            supplierId: new Guid(self::guid(900 + $supplierN)),
            supplierName: self::supplierName($supplierN),
            code: null,
            supplierBarcode: null,
            purchasePrice: Money::exclusive($purchasePrice),
            isDefault: $isDefault,
            leadTime: null,
            supplierCurrency: null,
            minPrice: null,
            maxPrice: null,
            averagePrice: null,
            stockItemId: new Guid(self::guid($stockItemN)),
        );
    }

    private static function nextWebhookId(): int
    {
        // intentional: far above real ShopWired ids (webhook_id is globally unique) and increasing — the guard drops an id at or below the last one
        return 9_000_000_000_000_000 + (\hrtime(true) >> 10) + ++self::$webhookSequence;
    }

    private function cleanUp(): void
    {
        $low = static::idBase();
        $high = static::idBase() + 99;
        $like = static::skuPrefix() . '%';
        $guidLike = \sprintf('%08x-%%', static::idBase());

        $productIds = DB::table('shopwired.products')->whereBetween('external_id', [$low, $high])->pluck('id')->all();
        $fragmentIds = \array_values(\array_unique([...$productIds, ...$this->trackedProductIds]));
        if ($fragmentIds !== []) {
            DB::table('catalog.product_list_fragments')->whereIn('product_id', $fragmentIds)->delete();
        }

        DB::table('shopwired.product_variations')->whereBetween('external_id', [$low, $high])->delete();
        DB::table('shopwired.products')->whereBetween('external_id', [$low, $high])->delete();
        DB::table('shopwired.categories')->whereBetween('external_id', [$low, $high])->delete();
        DB::table('shopwired.webhook_events')->whereBetween('subject_id', [$low, $high])->delete();
        DB::table('linnworks.stock_item_suppliers')
            ->where(static fn($q) => $q->where('stock_item_id', 'like', $guidLike)->orWhere('supplier_name', 'like', $like))
            ->delete();
        DB::table('linnworks.stock_items')
            ->where(static fn($q) => $q->where('stock_item_id', 'like', $guidLike)->orWhere('item_number', 'like', $like))
            ->delete();
        DB::table('catalog.product_extra_data')->where('sku', 'like', $like)->delete();
        DB::table('catalog.cost_price_changes')->where('sku', 'like', $like)->delete();
        DB::table('operations.price_periods')->where('sku', 'like', $like)->delete();
        DB::table('operations.sku_changes')
            ->where(static fn($q) => $q->where('old_sku', 'like', $like)->orWhere('new_sku', 'like', $like))
            ->delete();

        $this->trackedProductIds = [];
    }
}
