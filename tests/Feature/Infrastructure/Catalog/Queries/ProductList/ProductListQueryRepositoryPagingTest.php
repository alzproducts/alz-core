<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Catalog\Queries\ProductList;

use App\Application\Catalog\Queries\ProductListQueryParams;
use App\Application\Catalog\Results\ProductListEntryDTO;
use App\Application\Catalog\Results\ProductListPageResult;
use App\Application\Contracts\Catalog\ProductListReaderInterface;
use App\Domain\Catalog\Product\Enums\ProductFilterField;
use App\Domain\Catalog\Product\Enums\ProductSortField;
use App\Domain\Shared\Pagination\Enums\SortDirection;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Infrastructure\Catalog\Queries\ProductList\ProductListQueryRepository;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The page total and page contents, checked against counts and id lists built here from
 * shopwired.products and the fixtures.
 *
 * Fixtures live in a sentinel category, inserted in a rolled-back REPEATABLE READ transaction so
 * the expected lists and the list read share one snapshot even while other tests commit.
 */
#[CoversClass(ProductListQueryRepository::class)]
final class ProductListQueryRepositoryPagingTest extends TestCase
{
    private const int CATEGORY_EXTERNAL_ID = 2147481099;

    private const int PRODUCT_EXTERNAL_ID_BASE = 2147481000;

    private const int FIXTURE_COUNT = 23;

    private const string SEARCH_WORD = 'zqxpaging';

    private const int PER_PAGE = 5;

    /** @var list<array{externalId: int, internalId: string, price: float, isActive: bool, title: string}> */
    private array $fixtures = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // intentional: no DB trait — the isolation level must be set before the transaction's first query
        DB::beginTransaction();
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        $this->assertSentinelsUnused();
        $this->insertSentinelCategory();
        $this->insertProducts();
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    #[Test]
    public function the_default_first_page_holds_the_first_products_by_id_and_the_catalogue_total(): void
    {
        $page = $this->page(new ProductListQueryParams(pagination: PageRequest::from(1, self::PER_PAGE)));

        self::assertSame(self::catalogueTotal(), $page->total);
        self::assertSame(self::catalogueIdsById(0), self::externalIds($page));
    }

    #[Test]
    public function the_default_second_page_holds_the_next_products_by_id(): void
    {
        $page = $this->page(new ProductListQueryParams(pagination: PageRequest::from(2, self::PER_PAGE)));

        self::assertSame(self::catalogueTotal(), $page->total);
        self::assertSame(self::catalogueIdsById(self::PER_PAGE), self::externalIds($page));
    }

    #[Test]
    public function a_page_past_the_end_is_empty_but_keeps_the_catalogue_total(): void
    {
        $total = self::catalogueTotal();
        $pastTheEnd = \intdiv($total, self::PER_PAGE) + 2;

        $page = $this->page(new ProductListQueryParams(pagination: PageRequest::from($pastTheEnd, self::PER_PAGE)));

        self::assertGreaterThanOrEqual(self::FIXTURE_COUNT, $total);
        self::assertSame($total, $page->total);
        self::assertSame([], $page->entries);
    }

    #[Test]
    public function a_filtered_page_counts_and_pages_only_the_matching_products(): void
    {
        $active = \array_values(\array_filter($this->fixtures, static fn(array $fixture): bool => $fixture['isActive']));

        $page = $this->page(new ProductListQueryParams(
            pagination: PageRequest::from(2, self::PER_PAGE),
            filters: [ProductFilterField::CategoryId->value => self::CATEGORY_EXTERNAL_ID, ProductFilterField::IsActive->value => true],
        ));

        self::assertSame(\count($active), $page->total);
        self::assertSame(\array_slice(self::byId($active), self::PER_PAGE, self::PER_PAGE), self::externalIds($page));
    }

    #[Test]
    public function a_searched_page_counts_the_matches_and_orders_them_by_title(): void
    {
        $matches = \array_values(\array_filter(
            $this->fixtures,
            static fn(array $fixture): bool => \str_contains($fixture['title'], self::SEARCH_WORD),
        ));
        \usort($matches, static fn(array $a, array $b): int => \strcmp($a['title'], $b['title']));

        $page = $this->page(new ProductListQueryParams(
            pagination: PageRequest::from(2, self::PER_PAGE),
            filters: [ProductFilterField::CategoryId->value => self::CATEGORY_EXTERNAL_ID, ProductFilterField::Search->value => self::SEARCH_WORD],
        ));

        self::assertSame(\count($matches), $page->total);
        self::assertSame(\array_slice(\array_column($matches, 'externalId'), self::PER_PAGE, self::PER_PAGE), self::externalIds($page));
    }

    #[Test]
    public function a_search_matching_nothing_has_a_zero_total(): void
    {
        $page = $this->page(new ProductListQueryParams(
            pagination: PageRequest::from(1, self::PER_PAGE),
            filters: [ProductFilterField::CategoryId->value => self::CATEGORY_EXTERNAL_ID, ProductFilterField::Search->value => 'zqxnomatch'],
        ));

        self::assertSame(0, $page->total);
        self::assertSame([], $page->entries);
    }

    #[Test]
    public function a_sorted_page_orders_by_the_sort_column_then_id(): void
    {
        $sorted = $this->fixtures;
        \usort($sorted, static fn(array $a, array $b): int => $b['price'] <=> $a['price'] ?: \strcmp($a['internalId'], $b['internalId']));

        $page = $this->page(new ProductListQueryParams(
            pagination: PageRequest::from(1, self::PER_PAGE),
            sortField: ProductSortField::Price,
            sortDirection: SortDirection::Desc,
            filters: [ProductFilterField::CategoryId->value => self::CATEGORY_EXTERNAL_ID],
        ));

        self::assertSame(self::FIXTURE_COUNT, $page->total);
        self::assertSame(\array_slice(\array_column($sorted, 'externalId'), 0, self::PER_PAGE), self::externalIds($page));
    }

    private function page(ProductListQueryParams $query): ProductListPageResult
    {
        return $this->app->make(ProductListReaderInterface::class)->page($query);
    }

    private static function catalogueTotal(): int
    {
        return DB::table('shopwired.products')->count();
    }

    /**
     * @return list<int>
     */
    private static function catalogueIdsById(int $offset): array
    {
        return \array_map(
            static fn(mixed $id): int => (int) $id,
            DB::table('shopwired.products')->orderBy('id')->offset($offset)->limit(self::PER_PAGE)->pluck('external_id')->all(),
        );
    }

    /**
     * Postgres orders uuids bytewise, which matches strcmp on their canonical hex form.
     *
     * @param list<array{externalId: int, internalId: string, price: float, isActive: bool, title: string}> $fixtures
     *
     * @return list<int>
     */
    private static function byId(array $fixtures): array
    {
        \usort($fixtures, static fn(array $a, array $b): int => \strcmp($a['internalId'], $b['internalId']));

        return \array_column($fixtures, 'externalId');
    }

    /**
     * @return list<int>
     */
    private static function externalIds(ProductListPageResult $page): array
    {
        return \array_map(static function (ProductListEntryDTO $entry): int {
            if ($entry->view !== null) {
                return $entry->view->id->value;
            }

            $body = \json_decode((string) $entry->cachedBody, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertIsInt($body['id'] ?? null);

            return $body['id'];
        }, $page->entries);
    }

    /**
     * Prices repeat every five fixtures so the price sort needs its id tiebreak; every third
     * fixture is inactive; titles alternate between matching the search word and not.
     */
    private function insertProducts(): void
    {
        $now = \now();

        for ($i = 0; $i < self::FIXTURE_COUNT; $i++) {
            $externalId = self::PRODUCT_EXTERNAL_ID_BASE + $i;
            $price = 10.0 + ($i % 5);
            $isActive = $i % 3 !== 0;
            $title = \sprintf($i % 2 === 0 ? 'Paging fixture %s %02d' : 'Paging fixture plain %02d', self::SEARCH_WORD, $i);
            $internalId = DB::table('shopwired.products')->insertGetId([
                'external_id' => $externalId,
                'sku' => 'GUARD-PAGING-' . $externalId,
                'title' => $title,
                'slug' => 'guard-paging-' . $externalId,
                'url' => '/guard-paging-' . $externalId,
                'price' => $price,
                'sale_price' => null,
                'is_active' => $isActive,
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
            $this->fixtures[] = ['externalId' => $externalId, 'internalId' => $internalId, 'price' => $price, 'isActive' => $isActive, 'title' => $title];
        }
    }

    private function insertSentinelCategory(): void
    {
        $now = \now();
        DB::table('shopwired.categories')->insert([
            'external_id' => self::CATEGORY_EXTERNAL_ID,
            'shopwired_created_at' => $now,
            'title' => 'Paging fixture category',
            'slug' => 'guard-paging-category',
            'url' => '/guard-paging-category',
            'active' => true,
            'featured' => false,
            'trade_only' => false,
            'sort_order' => 0,
            'meta_no_index' => false,
            'parent_ids' => \json_encode([]),
            'custom_fields' => \json_encode([]),
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
                ->whereBetween('external_id', [self::PRODUCT_EXTERNAL_ID_BASE, self::PRODUCT_EXTERNAL_ID_BASE + self::FIXTURE_COUNT])
                ->exists(),
            'Sentinel product external_ids already exist',
        );
    }
}
