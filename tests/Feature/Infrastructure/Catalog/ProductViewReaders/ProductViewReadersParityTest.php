<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Catalog\ProductViewReaders;

use App\Application\Catalog\Commands\ProductFilterChangeCommand;
use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Application\Contracts\Shopwired\FilterGroupRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\Filters\ValueObjects\FilterGroupDefinition;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\RelatedProducts\ValueObjects\RelatedProductsAlgorithmParams;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\Uuid;
use App\Infrastructure\Catalog\Repositories\CreditTierDriftSql;
use App\Infrastructure\Catalog\Repositories\MarginTierDriftSql;
use App\Infrastructure\Catalog\Repositories\OffersFilterQueryRepository;
use App\Infrastructure\Catalog\Repositories\RelatedProductsQueryRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentProductRepository;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use ReflectionObject;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\TestCase;
use UnitEnum;

/**
 * The readers of catalog.products_view and catalog.product_variations_view other than the products
 * list return, on this class's fixtures, exactly what they returned at the commit COR-258 started
 * from (Fixtures/README.md). Everything runs in one rolled-back transaction, so neither the shared
 * database's rows nor parallel tests can reach the fixtures or the readers' output.
 */
#[CoversNothing]
final class ProductViewReadersParityTest extends TestCase
{
    use AuthenticatesAsApprovedUser;

    /** Set to 1 to rewrite the golden files instead of comparing against them. */
    public const string CAPTURE_ENV = 'PRODUCT_VIEW_READERS_CAPTURE';

    private const int BASE = 2147481500;

    private const string SKU_PREFIX = 'PVR-';

    /** Later than any real snapshot, so the fixtures' rows are the only "latest" popularity and credit tiers. */
    private const string SNAPSHOT_DATE = '2099-12-31';

    private const string FIXED_TIME = '2026-10-01 09:30:00+00';

    private const int JSON_FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        DB::setDefaultConnection('pgsql');
        // intentional: no DB trait — under --parallel it swaps to a per-worker database; a plain transaction keeps the fixtures private
        DB::beginTransaction();
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    #[Test]
    public function every_other_reader_matches_the_starting_commit_on_the_same_fixtures(): void
    {
        $this->bindDefinitions();
        $this->insertFixtures();
        self::refreshIfMaterialized();

        $outputs = [
            'detail-product-views' => $this->detailViews(),
            'margin-tier-drift' => self::driftRows(MarginTierDriftSql::SQL),
            'credit-tier-drift' => self::driftRows(CreditTierDriftSql::SQL),
            'related-products' => $this->relatedProducts(),
            'variations-endpoint' => $this->variationsEndpoint(),
            'offers-filters' => $this->offersFilters(),
        ];

        foreach ($outputs as $name => $output) {
            self::assertNotSame([], $output, "{$name} returned nothing for the fixtures, so the comparison would prove nothing");
            $json = \json_encode(self::normalize($output), self::JSON_FLAGS) . "\n";
            $path = __DIR__ . "/Fixtures/{$name}.json";

            if (\getenv(self::CAPTURE_ENV) === '1') {
                \file_put_contents($path, $json);
            }

            self::assertFileExists($path, "No golden file for {$name}: see Fixtures/README.md");
            self::assertSame(\file_get_contents($path), $json, "{$name} differs from the starting commit");
        }
    }

    /**
     * Variations are sorted by id: the detail query loads them unordered, so their order follows the
     * query plan, which differs between an empty database and a populated one at either commit.
     *
     * @return array<int, mixed>
     */
    private function detailViews(): array
    {
        $repository = $this->app->make(EloquentProductRepository::class);
        $views = [];

        foreach (\range(0, 5) as $n) {
            $view = self::normalize($repository->findDetailedProductView(IntId::from(self::productId($n)), ProductInclude::cases()));
            self::assertIsArray($view);
            if (\is_array($view['variations'] ?? null)) {
                \usort($view['variations'], static fn(mixed $a, mixed $b): int => self::variationKey($a) <=> self::variationKey($b));
            }
            $views[self::productId($n)] = $view;
        }

        return $views;
    }

    private static function variationKey(mixed $variation): int
    {
        self::assertIsArray($variation);
        self::assertIsArray($variation['id']);
        self::assertIsInt($variation['id']['value']);

        return $variation['id']['value'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function driftRows(string $sql): array
    {
        $rows = DB::select(
            "SELECT * FROM ({$sql}) AS drift WHERE external_id BETWEEN ? AND ? ORDER BY external_id",
            [self::productId(0), self::productId(99)],
        );

        return \array_map(static fn(object $row): array => \get_object_vars($row), $rows);
    }

    /**
     * Fixture products and their fixture related products, in rank order. The shared database's
     * products are dropped: the fixtures share categories and a title word no real product has, and
     * {@see self::relatedParams()} leaves slots to spare, so no real product displaces a fixture.
     *
     * @return array<int, list<int>>
     */
    private function relatedProducts(): array
    {
        $map = $this->app->make(RelatedProductsQueryRepository::class)->computeRelatedProducts(self::relatedParams());
        $related = [];

        foreach ($map as $productId => $relatedIds) {
            if (self::isFixture($productId)) {
                $related[$productId] = \array_values(\array_filter(
                    \array_map(static fn(IntId $id): int => $id->value, $relatedIds),
                    self::isFixture(...),
                ));
            }
        }
        \ksort($related);

        return $related;
    }

    /**
     * @return array<string, mixed>
     */
    private function variationsEndpoint(): array
    {
        $this->asApprovedUser();
        $bodies = [];

        foreach ([self::categoryId(0), self::categoryId(2)] as $categoryId) {
            $response = $this->getJson('/api/products/variations?' . \http_build_query([
                'category_id' => $categoryId,
                'include' => 'sale_settings,inventory,suppliers',
                'per_page' => 100,
            ]));
            $response->assertOk();
            $bodies["category-{$categoryId}"] = $response->json();
        }

        return $bodies;
    }

    /**
     * @return list<ProductFilterChangeCommand>
     */
    private function offersFilters(): array
    {
        $commands = \array_values(\array_filter(
            $this->app->make(OffersFilterQueryRepository::class)->getProductsWithChangedOffersFilters(),
            static fn(ProductFilterChangeCommand $command): bool => self::isFixture($command->productId->value),
        ));
        \usort($commands, static fn(ProductFilterChangeCommand $a, ProductFilterChangeCommand $b): int => $a->productId->value <=> $b->productId->value);

        return $commands;
    }

    private static function relatedParams(): RelatedProductsAlgorithmParams
    {
        return new RelatedProductsAlgorithmParams(
            categoryWeight: 0.45,
            titleWeight: 0.35,
            popularityWeight: 0.2,
            maxResults: 20,
            minContentScore: 0.05,
            defaultPopularity: 0.01,
            excludeCompareList: true,
        );
    }

    /**
     * The definitions registry and filter groups come from the shared database, so they are pinned here.
     */
    private function bindDefinitions(): void
    {
        $definitions = [];
        $fields = [
            'free_delivery' => CustomFieldType::List,
            'discontinued' => CustomFieldType::Text,
            'other_stock_status' => CustomFieldType::Text,
            'custom_label_0' => CustomFieldType::Text,
            'custom_label_1' => CustomFieldType::Text,
            'related_pins' => CustomFieldType::ProductList,
            'related_exclude_self' => CustomFieldType::Toggle,
        ];

        foreach (\array_keys($fields) as $i => $name) {
            $definitions[] = new ConfiguredFieldDefinition(
                internalId: new Uuid(self::uuid(700 + $i)),
                base: new CustomFieldDefinition(
                    id: self::BASE + $i,
                    name: $name,
                    type: $fields[$name],
                    label: null,
                    itemType: CustomFieldItemType::Product,
                    sortOrder: $i,
                    allowedValues: $name === 'free_delivery' ? ['Standard', 'Express'] : null,
                ),
                generalSettings: null,
                productSettings: null,
            );
        }

        $this->mock(CustomFieldRepositoryInterface::class, static function (MockInterface $mock) use ($definitions): void {
            $mock->allows('findAll')->andReturn($definitions);
        });
        $this->mock(FilterGroupRepositoryInterface::class, static function (MockInterface $mock): void {
            $mock->allows('findAll')->andReturn([new FilterGroupDefinition(id: self::BASE, title: 'Offers', optionNo: 14, sortOrder: 1)]);
        });
    }

    private function insertFixtures(): void
    {
        DB::table('catalog.margin_tier_thresholds')->upsert(
            [['id' => 1, 'low_max_pct' => '20.00', 'standard_max_pct' => '40.00', 'updated_at' => self::FIXED_TIME]],
            ['id'],
            ['low_max_pct', 'standard_max_pct', 'updated_at'],
        );

        $this->insertCategories();
        $this->insertProducts();
        $this->insertVariations();
        $this->insertStock();
        $this->insertPricingHistory();
        $this->insertPopularity();
    }

    private function insertCategories(): void
    {
        $categories = [
            0 => ['title' => 'Quokkaparity kitchen', 'parent_ids' => [], 'main' => true],
            1 => ['title' => 'Quokkaparity vexmoors', 'parent_ids' => [self::categoryId(0)], 'main' => false],
            2 => ['title' => 'Quokkaparity trillows', 'parent_ids' => [], 'main' => true],
            3 => ['title' => 'Quokkaparity gift ideas', 'parent_ids' => [self::categoryId(2)], 'main' => false],
        ];

        foreach ($categories as $n => $category) {
            DB::table('shopwired.categories')->insert([
                'external_id' => self::categoryId($n),
                'shopwired_created_at' => self::FIXED_TIME,
                'title' => $category['title'],
                'slug' => "pvr-c{$n}",
                'url' => "/pvr-c{$n}",
                'active' => true,
                'featured' => false,
                'trade_only' => false,
                'sort_order' => $n,
                'meta_no_index' => false,
                'parent_ids' => \json_encode($category['parent_ids']),
                'custom_fields' => \json_encode($category['main'] ? ['is_main_category' => true] : (object) []),
                'created_at' => self::FIXED_TIME,
                'updated_at' => self::FIXED_TIME,
            ]);
        }
    }

    private function insertProducts(): void
    {
        $products = [
            0 => [
                'title' => 'Quokkaparity Vexmoor Aldrith', 'price' => '120.00', 'category_ids' => [self::categoryId(1)],
                'gtin' => '5012345678900', 'compare_price' => '150.00', 'stock' => 9, 'sort_order' => 3,
                'custom_fields' => ['free_delivery' => 'Standard', 'custom_label_0' => 'Gold', 'custom_label_1' => '1 - Low margin', 'related_pins' => [self::productId(5)]],
                'filters' => ['14' => ['On Sale'], '3' => ['Blue']],
                'images' => [['id' => 1, 'url' => 'https://example.test/pvr-p0.jpg', 'description' => 'Front', 'sort_order' => 0]],
            ],
            1 => [
                'title' => 'Quokkaparity Vexmoor Brindle Deluxe', 'price' => '80.00', 'sale_price' => '60.00', 'vat_exclusive' => true,
                'category_ids' => [self::categoryId(1), self::categoryId(2)], 'stock' => 0,
                'custom_fields' => ['discontinued' => 'Yes', 'other_stock_status' => 'Back soon', 'custom_label_1' => '3 - High margin'],
            ],
            2 => [
                'title' => 'Quokkaparity Trillow Gorvane', 'price' => '45.50', 'category_ids' => [self::categoryId(3), self::categoryId(2)], 'stock' => 0,
                'custom_fields' => ['free_delivery' => 'Express'],
                'filters' => ['14' => ['on sale']],
            ],
            3 => ['title' => 'Quokkaparity Trillow Dusk', 'price' => '10.00', 'is_active' => false, 'category_ids' => [self::categoryId(2)], 'stock' => 5],
            4 => [
                'title' => 'Quokkaparity Plimsett Eskar', 'price' => '33.00', 'category_ids' => [self::categoryId(0)], 'stock' => 0,
                'custom_fields' => ['discontinued' => 'Discontinued'],
            ],
            5 => [
                'title' => 'Quokkaparity Vexmoor Zentral', 'price' => '25.00', 'category_ids' => [self::categoryId(1)], 'stock' => 2,
                'custom_fields' => ['related_exclude_self' => true],
            ],
        ];

        foreach ($products as $n => $product) {
            DB::table('shopwired.products')->insert([
                'id' => self::uuid($n),
                'external_id' => self::productId($n),
                'sku' => self::SKU_PREFIX . "P{$n}",
                'gtin' => $product['gtin'] ?? null,
                'title' => $product['title'],
                'description' => "<p>{$product['title']}</p>",
                'slug' => "pvr-p{$n}",
                'url' => "/pvr-p{$n}",
                'price' => $product['price'],
                'sale_price' => $product['sale_price'] ?? null,
                'compare_price' => $product['compare_price'] ?? null,
                'stock' => $product['stock'],
                'sort_order' => $product['sort_order'] ?? null,
                'is_active' => $product['is_active'] ?? true,
                'vat_exclusive' => $product['vat_exclusive'] ?? false,
                'meta_title' => "Meta {$n}",
                'category_ids' => \json_encode($product['category_ids']),
                'images' => \json_encode($product['images'] ?? []),
                'custom_fields' => \json_encode($product['custom_fields'] ?? (object) []),
                'filters' => \json_encode($product['filters'] ?? (object) []),
                'shopwired_created_at' => self::FIXED_TIME,
                'shopwired_updated_at' => self::FIXED_TIME,
                'created_at' => self::FIXED_TIME,
                'updated_at' => self::FIXED_TIME,
            ]);
        }

        DB::table('catalog.product_extra_data')->insert(['id' => self::uuid(800), 'sku' => self::SKU_PREFIX . 'P0', 'rrp' => '150.00', 'created_at' => self::FIXED_TIME, 'updated_at' => self::FIXED_TIME]);
    }

    private function insertVariations(): void
    {
        $variations = [
            0 => ['product' => 1, 'sku' => 'V0', 'price' => '85.00', 'stock' => 3],
            1 => ['product' => 1, 'sku' => 'V1', 'price' => null, 'stock' => 1],
            2 => ['product' => 1, 'sku' => null, 'price' => '90.00', 'sale_price' => '70.00', 'stock' => 4],
            3 => ['product' => 2, 'sku' => 'V3', 'price' => '45.50', 'sale_price' => '40.00', 'stock' => 6],
            4 => ['product' => 2, 'sku' => 'V4', 'price' => '47.00', 'stock' => 0],
        ];

        foreach ($variations as $n => $variation) {
            DB::table('shopwired.product_variations')->insert([
                'id' => self::uuid(100 + $n),
                'product_id' => self::uuid($variation['product']),
                'product_external_id' => self::productId($variation['product']),
                'external_id' => self::variationId($n),
                'sku' => $variation['sku'] === null ? null : self::SKU_PREFIX . $variation['sku'],
                'price' => $variation['price'],
                'sale_price' => $variation['sale_price'] ?? null,
                'stock' => $variation['stock'],
                'options' => \json_encode([['option_id' => 1, 'option_name' => 'Colour', 'value_id' => 10 + $n, 'value_name' => "Shade {$n}"]]),
                'created_at' => self::FIXED_TIME,
                'updated_at' => self::FIXED_TIME,
            ]);
        }
    }

    /**
     * Stock items for P0, V0 and V1 (V1 without a supplier); the other SKUs have none.
     */
    private function insertStock(): void
    {
        $items = [
            0 => ['sku' => 'P0', 'quantity' => 7, 'available' => 5, 'suppliers' => [[1, '30.00', true], [2, '28.00', false]]],
            1 => ['sku' => 'V0', 'quantity' => 3, 'available' => 3, 'suppliers' => [[1, '20.00', true]]],
            2 => ['sku' => 'V1', 'quantity' => 1, 'available' => 0, 'suppliers' => []],
        ];

        foreach ($items as $n => $item) {
            DB::table('linnworks.stock_items')->insert([
                'stock_item_id' => self::uuid(200 + $n),
                'item_number' => self::SKU_PREFIX . $item['sku'],
                'item_title' => "Quokkaparity stock item {$n}",
                'quantity' => $item['quantity'],
                'available' => $item['available'],
                'in_order' => $item['quantity'] - $item['available'],
                'due' => 2,
                'created_at' => self::FIXED_TIME,
                'updated_at' => self::FIXED_TIME,
            ]);

            foreach ($item['suppliers'] as [$supplier, $purchasePrice, $isDefault]) {
                DB::table('linnworks.stock_item_suppliers')->insert([
                    'id' => self::uuid(300 + ($n * 10) + $supplier),
                    'stock_item_id' => self::uuid(200 + $n),
                    'supplier_id' => self::uuid(400 + $supplier),
                    'supplier_name' => "Quokkaparity supplier {$supplier}",
                    'purchase_price' => $purchasePrice,
                    'is_default' => $isDefault,
                    'created_at' => self::FIXED_TIME,
                    'updated_at' => self::FIXED_TIME,
                ]);
            }
        }
    }

    private function insertPricingHistory(): void
    {
        DB::table('operations.price_periods')->insert([
            'id' => self::uuid(500),
            'sku' => self::SKU_PREFIX . 'V0',
            'base_price_gross' => '85.00',
            'sale_price_gross' => null,
            'effective_price_gross' => '85.00',
            'price_has_tax' => true,
            'effective_from' => '2026-09-15 08:00:00+00',
            'effective_to' => null,
            'created_at' => self::FIXED_TIME,
        ]);
        DB::table('catalog.cost_price_changes')->insert([
            'id' => self::uuid(501),
            'sku' => self::SKU_PREFIX . 'P0',
            'supplier_id' => self::uuid(401),
            'supplier_name' => 'Quokkaparity supplier 1',
            'old_cost_price' => '32.00',
            'new_cost_price' => '30.00',
            'changed_at' => '2026-09-20 12:00:00+00',
        ]);
    }

    private function insertPopularity(): void
    {
        $scores = ['main_qty' => 0, 'main_turnover' => 0, 'recent_qty' => 0, 'recent_turnover' => 0, 'main_qty_rank' => 0, 'main_turnover_rank' => 0, 'recent_qty_rank' => 0, 'recent_turnover_rank' => 0, 'main_score' => 0, 'recent_score' => 0, 'trend' => 0];

        foreach ([0 => [1, '9.50'], 1 => [3, '7.25'], 5 => [6, '5.00']] as $n => [$rank, $finalScore]) {
            DB::table('catalog.product_popularity_snapshots')->insert([
                'snapshot_date' => self::SNAPSHOT_DATE, 'algorithm_version' => 1, 'parent_external_id' => self::productId($n),
                'calculated_sort_order' => $rank, ...$scores, 'final_score' => $finalScore,
            ]);
        }

        foreach ([0 => 'A', 1 => 'B', 2 => 'C', 4 => null] as $n => $tier) {
            DB::table('catalog.credit_product_popularity_snapshots')->insert([
                'snapshot_date' => self::SNAPSHOT_DATE, 'algorithm_version' => 1, 'parent_external_id' => self::productId($n),
                'calculated_sort_order' => $n + 1, ...$scores, 'final_score' => 1, 'credit_tier' => $tier,
            ]);
        }

        foreach ([0 => [1, 2], 3 => [2, 4]] as $n => [$product, $rank]) {
            DB::table('catalog.sku_popularity_snapshots')->insert([
                'snapshot_date' => self::SNAPSHOT_DATE, 'algorithm_version' => 1, 'live_sku' => self::SKU_PREFIX . "V{$n}",
                'parent_external_id' => self::productId($product), 'variation_external_id' => self::variationId($n),
                'calculated_sort_order' => $rank, ...$scores, 'final_score' => 1,
            ]);
        }
    }

    /**
     * intentional: products_view is materialized at the starting commit, where the golden files are captured.
     */
    private static function refreshIfMaterialized(): void
    {
        if (DB::table('pg_matviews')->where('schemaname', 'catalog')->where('matviewname', 'products_view')->exists()) {
            DB::statement('REFRESH MATERIALIZED VIEW catalog.products_view');
        }
    }

    /**
     * JSON-encodable data keeping every stored property, private ones included, so any changed field shows.
     */
    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof UnitEnum => $value::class . '::' . $value->name,
            $value instanceof DateTimeInterface => $value->format('Y-m-d\TH:i:s.uP'),
            \is_object($value) => self::normalizeObject($value),
            \is_array($value) => \array_map(self::normalize(...), $value),
            default => $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeObject(object $value): array
    {
        $normalized = ['@class' => $value::class];

        foreach ((new ReflectionObject($value))->getProperties() as $property) {
            if (! $property->isStatic() && ! $property->isVirtual() && $property->isInitialized($value)) {
                $normalized[$property->getName()] = self::normalize($property->getValue($value));
            }
        }

        return $normalized;
    }

    private static function isFixture(int $externalId): bool
    {
        return $externalId >= self::BASE && $externalId < self::BASE + 100;
    }

    private static function productId(int $n): int
    {
        return self::BASE + $n;
    }

    private static function variationId(int $n): int
    {
        return self::BASE + 30 + $n;
    }

    private static function categoryId(int $n): int
    {
        return self::BASE + 90 + $n;
    }

    private static function uuid(int $n): string
    {
        return \sprintf('%08x-0000-4000-8000-%012x', self::BASE, $n);
    }
}
