<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

use App\Application\Catalog\Queries\ProductListQueryParams;
use App\Application\Catalog\Queries\VariationListQueryParams;
use App\Application\Catalog\Results\ProductListEntryDTO;
use App\Application\Contracts\Catalog\ProductListReaderInterface;
use App\Application\Contracts\Catalog\VariationQueryRepositoryInterface;
use App\Domain\Catalog\Product\Enums\ProductFilterField;
use App\Domain\Catalog\Product\Enums\ProductSortField;
use App\Domain\Catalog\Product\Enums\VariationFilterField;
use App\Domain\Catalog\Product\Enums\VariationSortField;
use App\Domain\Catalog\Product\ValueObjects\VariationListItem;
use App\Domain\Shared\Pagination\Enums\SortDirection;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paging the product and variation lists must return every row exactly once when
 * many rows tie on the sort value.
 *
 * Fixtures live in a sentinel main category (so the category filter isolates them
 * from the synced catalog) and are inserted in a rolled-back transaction.
 */
#[CoversNothing]
#[Group('integration')]
final class CatalogListPaginationTiebreakTest extends TestCase
{
    private const int CATEGORY_EXTERNAL_ID = 2147482999;

    private const int PRODUCT_EXTERNAL_ID_BASE = 2147482000;

    private const int VARIATION_EXTERNAL_ID_BASE = 2147482500;

    private const string SKU_PREFIX = 'GUARD-TIEBREAK-';

    private const string SEARCH_WORD = 'zqxtiebreak';

    private const string TIED_PRICE = '9.99';

    private const int TIED_COUNT = 30;

    private const int PER_PAGE = 10;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // intentional: no DB trait — under --parallel it swaps to a per-worker DB without synced data
        DB::beginTransaction();

        $this->assertSentinelsUnused();
        $this->insertSentinelCategory();
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    #[Test]
    public function product_price_sort_pages_tied_rows_exactly_once_ordered_by_id(): void
    {
        $fixtures = $this->insertProducts();

        $pagedIds = $this->pageProducts(sortField: ProductSortField::Price);

        self::assertSame(self::expectedOrder($fixtures), $pagedIds);
    }

    #[Test]
    public function product_default_title_sort_orders_duplicate_titles_by_id(): void
    {
        $fixtures = $this->insertProducts();

        $pagedIds = $this->pageProducts(sortField: ProductSortField::Title);

        self::assertSame(self::expectedOrderById($fixtures), $pagedIds);
    }

    #[Test]
    public function product_search_orders_by_rank_then_title_then_id(): void
    {
        $fixtures = $this->insertProducts();

        $orderClauses = [];
        Event::listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$orderClauses): void {
            if (\preg_match('/row_number\(\) over \(order by (.+?)\)\) as "page_pos"/i', $event->sql, $match) === 1) {
                $orderClauses[] = $match[1];
            }
        });

        $pagedIds = $this->pageProducts(sortField: null, search: self::SEARCH_WORD);

        self::assertSame(self::expectedOrderById($fixtures), $pagedIds);
        self::assertNotSame([], $orderClauses);
        foreach ($orderClauses as $orderClause) {
            self::assertSame(
                "ts_rank(to_tsvector('english', title), websearch_to_tsquery('english', ?)) DESC, \"title\" asc, \"id\" asc",
                $orderClause,
            );
        }
    }

    #[Test]
    public function variation_list_without_sort_pages_every_row_once_ordered_by_id(): void
    {
        $fixtures = $this->insertVariations();

        $pagedIds = $this->pageVariations(sortField: null);

        self::assertSame(self::expectedOrderById($fixtures), $pagedIds);
    }

    #[Test]
    public function variation_price_sort_pages_tied_rows_exactly_once_ordered_by_id(): void
    {
        $fixtures = $this->insertVariations();

        $pagedIds = $this->pageVariations(sortField: VariationSortField::Price);

        self::assertSame(self::expectedOrder($fixtures), $pagedIds);
    }

    #[Test]
    public function variation_popularity_sort_keeps_nulls_last_and_orders_equal_ranks_by_id(): void
    {
        $fixtures = $this->insertVariations();
        $ranked = $this->insertPopularityRanks($fixtures);

        // intentional: DESC — Postgres defaults DESC to NULLS FIRST, so this direction proves NULLS LAST is applied
        $pagedIds = $this->pageVariations(sortField: VariationSortField::Popularity, direction: SortDirection::Desc);

        self::assertSame(self::expectedPopularityDescOrder($ranked), $pagedIds);
    }

    /**
     * @return list<int>
     */
    private function pageProducts(?ProductSortField $sortField, ?string $search = null): array
    {
        $reader = $this->app->make(ProductListReaderInterface::class);
        $filters = [ProductFilterField::CategoryId->value => self::CATEGORY_EXTERNAL_ID];
        if ($search !== null) {
            $filters[ProductFilterField::Search->value] = $search;
        }

        $ids = [];
        $page = 1;
        do {
            $result = $reader->page(new ProductListQueryParams(
                pagination: PageRequest::from($page, self::PER_PAGE),
                sortField: $sortField,
                filters: $filters,
            ));
            foreach ($result->entries as $entry) {
                $ids[] = self::externalId($entry);
            }
            $page++;
        } while (($page - 1) * self::PER_PAGE < $result->total);

        return $ids;
    }

    private static function externalId(ProductListEntryDTO $entry): int
    {
        if ($entry->view !== null) {
            return $entry->view->id->value;
        }

        $body = \json_decode((string) $entry->cachedBody, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsInt($body['id'] ?? null);

        return $body['id'];
    }

    /**
     * @return list<int>
     */
    private function pageVariations(?VariationSortField $sortField, SortDirection $direction = SortDirection::Asc): array
    {
        $repository = $this->app->make(VariationQueryRepositoryInterface::class);

        $ids = [];
        $page = 1;
        do {
            $result = $repository->paginate(new VariationListQueryParams(
                pagination: PageRequest::from($page, self::PER_PAGE),
                sortField: $sortField,
                sortDirection: $direction,
                filters: [VariationFilterField::CategoryId->value => self::CATEGORY_EXTERNAL_ID],
            ));
            foreach ($result->items as $item) {
                \assert($item instanceof VariationListItem);
                $ids[] = $item->variation->id->value;
            }
            $page++;
        } while ($page <= $result->lastPage);

        return $ids;
    }

    /**
     * One cheaper and one dearer row bracket the tied block, so price stays the primary key.
     *
     * @return list<string>
     */
    private static function fixturePrices(): array
    {
        return ['99.99', ...\array_fill(0, self::TIED_COUNT, self::TIED_PRICE), '1.00'];
    }

    /**
     * @return list<array{externalId: int, internalId: string, price: string}>
     */
    private function insertProducts(): array
    {
        $now = \now();
        $fixtures = [];
        foreach (self::fixturePrices() as $i => $price) {
            $externalId = self::PRODUCT_EXTERNAL_ID_BASE + $i;
            $internalId = DB::table('shopwired.products')->insertGetId([
                'external_id' => $externalId,
                'sku' => self::SKU_PREFIX . $externalId,
                'title' => 'Tiebreak fixture ' . self::SEARCH_WORD,
                'slug' => 'guard-tiebreak-' . $externalId,
                'url' => '/guard-tiebreak-' . $externalId,
                'price' => $price,
                'sale_price' => null,
                'is_active' => true,
                'vat_exclusive' => false,
                'category_ids' => \json_encode([self::CATEGORY_EXTERNAL_ID]),
                'images' => \json_encode([]),
                'custom_fields' => \json_encode((object) []),
                'filters' => \json_encode((object) []),
                'shopwired_created_at' => $now,
                'shopwired_updated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            \assert(\is_string($internalId));
            $fixtures[] = ['externalId' => $externalId, 'internalId' => $internalId, 'price' => $price];
        }

        return $fixtures;
    }

    /**
     * @return list<array{externalId: int, internalId: string, price: string, sku: string}>
     */
    private function insertVariations(): array
    {
        $now = \now();
        $parentExternalId = self::PRODUCT_EXTERNAL_ID_BASE;
        $parentId = DB::table('shopwired.products')->insertGetId([
            'external_id' => $parentExternalId,
            'sku' => self::SKU_PREFIX . $parentExternalId,
            'title' => 'Tiebreak fixture parent',
            'slug' => 'guard-tiebreak-' . $parentExternalId,
            'url' => '/guard-tiebreak-' . $parentExternalId,
            'price' => self::TIED_PRICE,
            'sale_price' => null,
            'is_active' => true,
            'vat_exclusive' => false,
            'category_ids' => \json_encode([self::CATEGORY_EXTERNAL_ID]),
            'images' => \json_encode([]),
            'custom_fields' => \json_encode((object) []),
            'filters' => \json_encode((object) []),
            'shopwired_created_at' => $now,
            'shopwired_updated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fixtures = [];
        foreach (self::fixturePrices() as $i => $price) {
            $externalId = self::VARIATION_EXTERNAL_ID_BASE + $i;
            $sku = self::SKU_PREFIX . $externalId;
            $internalId = DB::table('shopwired.product_variations')->insertGetId([
                'product_id' => $parentId,
                'product_external_id' => $parentExternalId,
                'external_id' => $externalId,
                'sku' => $sku,
                'price' => $price,
                'sale_price' => null,
                'stock' => 0,
                'options' => \json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            \assert(\is_string($internalId));
            $fixtures[] = ['externalId' => $externalId, 'internalId' => $internalId, 'price' => $price, 'sku' => $sku];
        }

        return $fixtures;
    }

    /**
     * Ranks cycle 2, 1, unranked across the fixtures, giving three tied groups (unranked = NULL rank).
     *
     * @param list<array{externalId: int, internalId: string, price: string, sku: string}> $fixtures
     *
     * @return list<array{externalId: int, internalId: string, rank: int|null}>
     */
    private function insertPopularityRanks(array $fixtures): array
    {
        $algorithmVersion = DB::table('catalog.sku_popularity_ranking_config')->min('algorithm_version');
        self::assertIsInt($algorithmVersion, 'catalog.sku_popularity_ranking_config must hold a version');
        $latest = DB::table('catalog.sku_popularity_snapshots')->max('snapshot_date');
        $snapshotDate = \is_string($latest) ? $latest : \now()->toDateString();

        $ranked = [];
        foreach ($fixtures as $i => $fixture) {
            $rank = [2, 1, null][$i % 3];
            if ($rank !== null) {
                DB::table('catalog.sku_popularity_snapshots')->insert([
                    'snapshot_date' => $snapshotDate,
                    'algorithm_version' => $algorithmVersion,
                    'live_sku' => $fixture['sku'],
                    'parent_external_id' => self::PRODUCT_EXTERNAL_ID_BASE,
                    'variation_external_id' => $fixture['externalId'],
                    'calculated_sort_order' => $rank,
                    'main_qty' => 0,
                    'main_turnover' => 0,
                    'recent_qty' => 0,
                    'recent_turnover' => 0,
                    'main_qty_rank' => 0,
                    'main_turnover_rank' => 0,
                    'recent_qty_rank' => 0,
                    'recent_turnover_rank' => 0,
                    'main_score' => 0,
                    'recent_score' => 0,
                    'final_score' => 0,
                    'trend' => 0,
                ]);
            }
            $ranked[] = ['externalId' => $fixture['externalId'], 'internalId' => $fixture['internalId'], 'rank' => $rank];
        }

        return $ranked;
    }

    /**
     * Postgres orders uuids bytewise, which matches strcmp on their canonical hex form.
     *
     * @param list<array{externalId: int, internalId: string, rank: int|null}> $ranked
     *
     * @return list<int>
     */
    private static function expectedPopularityDescOrder(array $ranked): array
    {
        \usort($ranked, static fn(array $a, array $b): int => [$a['rank'] === null, $b['rank'] ?? 0]
            <=> [$b['rank'] === null, $a['rank'] ?? 0]
            ?: \strcmp($a['internalId'], $b['internalId']));

        return \array_column($ranked, 'externalId');
    }

    /**
     * @param list<array{externalId: int, internalId: string, price: string}> $fixtures
     *
     * @return list<int>
     */
    private static function expectedOrder(array $fixtures): array
    {
        \usort($fixtures, static fn(array $a, array $b): int => (float) $a['price'] <=> (float) $b['price']
            ?: \strcmp($a['internalId'], $b['internalId']));

        return \array_column($fixtures, 'externalId');
    }

    /**
     * @param list<array{externalId: int, internalId: string}> $fixtures
     *
     * @return list<int>
     */
    private static function expectedOrderById(array $fixtures): array
    {
        \usort($fixtures, static fn(array $a, array $b): int => \strcmp($a['internalId'], $b['internalId']));

        return \array_column($fixtures, 'externalId');
    }

    private function insertSentinelCategory(): void
    {
        $now = \now();
        DB::table('shopwired.categories')->insert([
            'external_id' => self::CATEGORY_EXTERNAL_ID,
            'shopwired_created_at' => $now,
            'title' => 'Tiebreak fixture category',
            'slug' => 'guard-tiebreak-category',
            'url' => '/guard-tiebreak-category',
            'active' => true,
            'featured' => false,
            'trade_only' => false,
            'sort_order' => 0,
            'meta_no_index' => false,
            'parent_ids' => \json_encode([]),
            'custom_fields' => \json_encode(['is_main_category' => true]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function assertSentinelsUnused(): void
    {
        self::assertFalse(
            DB::table('shopwired.categories')->where('external_id', self::CATEGORY_EXTERNAL_ID)->exists(),
            'Sentinel category external_id already exists',
        );
        self::assertFalse(
            DB::table('shopwired.products')
                ->whereBetween('external_id', [self::PRODUCT_EXTERNAL_ID_BASE, self::PRODUCT_EXTERNAL_ID_BASE + self::TIED_COUNT + 1])
                ->exists(),
            'Sentinel product external_ids already exist',
        );
        self::assertFalse(
            DB::table('shopwired.product_variations')
                ->whereBetween('external_id', [self::VARIATION_EXTERNAL_ID_BASE, self::VARIATION_EXTERNAL_ID_BASE + self::TIED_COUNT + 1])
                ->exists(),
            'Sentinel variation external_ids already exist',
        );
    }
}
