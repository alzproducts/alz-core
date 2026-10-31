<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

use App\Application\Catalog\Queries\ProductListQueryParams;
use App\Application\Catalog\Results\ProductListPageResult;
use App\Application\Contracts\Catalog\ProductListReaderInterface;
use App\DevTools\Catalog\ProductList\InProcessApiClient;
use App\DevTools\Catalog\ProductList\JsonDiff;
use App\DevTools\Catalog\ProductList\ProductListRequestShapes;
use App\DevTools\Catalog\ProductList\SnapshotStore;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\TestCase;

/**
 * Every products list shape must answer byte for byte as the `reference` capture taken before
 * the fingerprinted read path, both with no stored fragments and with every fragment stored.
 *
 * Opt-in (`--group=list-parity`): it needs the gitignored reference under tmp/cor-258/ and the
 * seed it was captured on. Runs inside a rolled-back transaction, so fragments it stores vanish.
 */
#[CoversNothing]
#[Group('list-parity')]
final class ProductListParityTest extends TestCase
{
    use AuthenticatesAsApprovedUser;

    private const string REFERENCE = 'reference';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // intentional: no DB trait — under --parallel it swaps to a per-worker DB without the seed
        DB::beginTransaction();
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    #[Test]
    public function every_list_shape_matches_the_reference_with_an_empty_and_a_warm_fragment_table(): void
    {
        $store = $this->app->make(SnapshotStore::class);
        self::assertTrue(
            $store->exists(self::REFERENCE),
            'No tmp/cor-258/reference capture: regenerate it at the manifest SHA (see the COR-258 plan) before running list-parity.',
        );

        DB::statement('DELETE FROM catalog.product_list_fragments');
        $misses = $this->countMisses();

        $this->assertShapesMatchReference($store, 'empty fragment table');
        self::assertGreaterThan(0, $misses->total, 'The cold pass must render and store fragments');
        self::assertGreaterThan(0, DB::table('catalog.product_list_fragments')->count());

        $misses->total = 0;
        $this->assertShapesMatchReference($store, 'warm fragment table');
        self::assertSame(0, $misses->total, 'The warm pass must serve every product from a stored fragment');
    }

    private function assertShapesMatchReference(SnapshotStore $store, string $pass): void
    {
        $api = $this->asApprovedUser()->app->make(InProcessApiClient::class);

        foreach (ProductListRequestShapes::PRODUCT_LIST as $name => $uri) {
            $expected = $store->read(self::REFERENCE, $name);
            $actual = $api->get($uri);

            self::assertSame(
                [],
                JsonDiff::diff(\json_decode($expected, flags: \JSON_THROW_ON_ERROR), \json_decode($actual, flags: \JSON_THROW_ON_ERROR)),
                "{$name} ({$pass}) differs from the reference",
            );
            self::assertSame($expected, $actual, "{$name} ({$pass}) bytes differ from the reference although the JSON is equal");
        }
    }

    /**
     * Wraps the bound reader so each page's misses (entries rendered rather than served stored) are counted.
     */
    private function countMisses(): object
    {
        $counter = new class {
            public int $total = 0;
        };
        $reader = $this->app->make(ProductListReaderInterface::class);

        $this->app->instance(ProductListReaderInterface::class, new readonly class ($reader, $counter) implements ProductListReaderInterface {
            public function __construct(private ProductListReaderInterface $reader, private object $counter) {}

            public function page(ProductListQueryParams $query): ProductListPageResult
            {
                $page = $this->reader->page($query);

                foreach ($page->entries as $entry) {
                    $this->counter->total += $entry->cachedBody === null ? 1 : 0;
                }

                return $page;
            }

            public function rebuild(array $productIds, array $includes): array
            {
                return $this->reader->rebuild($productIds, $includes);
            }
        });

        return $counter;
    }
}
