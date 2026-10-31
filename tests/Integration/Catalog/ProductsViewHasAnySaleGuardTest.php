<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

use App\Application\Catalog\Queries\ProductListQueryParams;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\Product\Enums\ProductFilterField;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Domain\ValueObjects\IntId;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guard test: `catalog.products_view.has_any_sale` must equal "master SKU or any
 * variation SKU on sale", and sale-category drift detection must follow it.
 *
 * Runs against the seeded catalog (or opt-in synced prod data) plus an
 * inheritance-only fixture (variation with no sale price of its own, on sale via
 * its product's) inserted in a rolled-back transaction.
 */
#[CoversNothing]
#[Group('integration')]
final class ProductsViewHasAnySaleGuardTest extends TestCase
{
    private const int PRODUCT_EXTERNAL_ID = 2147483001;

    private const int VARIATION_EXTERNAL_ID = 2147483002;

    private const string PRODUCT_SKU = 'GUARD-HAS-ANY-SALE-2147483001';

    private const int NON_DRIFTED_SAMPLE_SIZE = 25;

    private int $saleCategoryId;

    private ProductRepositoryInterface $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // intentional: no DB trait — under --parallel it swaps to a per-worker DB without synced data
        DB::beginTransaction();

        $saleCategoryId = \config('shopwired.sale_category_id');
        self::assertIsInt($saleCategoryId, 'shopwired.sale_category_id must be configured');
        self::assertGreaterThan(0, $saleCategoryId, 'shopwired.sale_category_id must be a positive int');
        $this->saleCategoryId = $saleCategoryId;

        $this->insertInheritanceOnlyFixture();

        $this->repository = $this->app->make(ProductRepositoryInterface::class);
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    #[Test]
    public function has_any_sale_matches_master_or_any_variation_on_sale(): void
    {
        $mismatched = DB::select(<<<'SQL'
            SELECT p.external_id
            FROM catalog.products_view p
            WHERE p.has_any_sale IS DISTINCT FROM (
                p.is_on_sale
                OR EXISTS (
                    SELECT 1
                    FROM catalog.product_variations_view pv
                    WHERE pv.product_id = p.id
                      AND pv.is_on_sale
                )
            )
            ORDER BY p.external_id
            LIMIT 20
            SQL);

        self::assertSame([], \array_map(static fn(object $row): int => (int) $row->external_id, $mismatched));
    }

    #[Test]
    public function drift_queries_return_exactly_products_whose_has_any_sale_differs_from_sale_category_membership(): void
    {
        $expectedDrifted = $this->externalIds(
            'SELECT p.external_id FROM catalog.products_view p '
            . 'WHERE p.has_any_sale IS DISTINCT FROM (p.category_ids @> ?::jsonb) ORDER BY p.external_id',
        );

        $actualDrifted = $this->repository->getAllProductsWithSaleStateDrift($this->saleCategoryId);
        \sort($actualDrifted);

        self::assertSame($expectedDrifted, $actualDrifted);

        foreach ($expectedDrifted as $externalId) {
            self::assertTrue(
                $this->repository->hasSaleStateDrift(IntId::from($externalId), $this->saleCategoryId),
                "Product {$externalId} has drift but hasSaleStateDrift() returned false",
            );
        }

        $nonDriftedSample = $this->externalIds(
            'SELECT p.external_id FROM catalog.products_view p '
            . 'WHERE p.has_any_sale = (p.category_ids @> ?::jsonb) ORDER BY p.external_id LIMIT '
            . self::NON_DRIFTED_SAMPLE_SIZE,
        );
        self::assertNotSame([], $nonDriftedSample);

        foreach ($nonDriftedSample as $externalId) {
            self::assertFalse(
                $this->repository->hasSaleStateDrift(IntId::from($externalId), $this->saleCategoryId),
                "Product {$externalId} has no drift but hasSaleStateDrift() returned true",
            );
        }
    }

    #[Test]
    public function inheritance_only_fixture_has_any_sale_drifts_and_matches_has_any_sale_filter(): void
    {
        $row = DB::selectOne(
            'SELECT p.has_any_sale, p.is_on_sale FROM catalog.products_view p WHERE p.external_id = ?',
            [self::PRODUCT_EXTERNAL_ID],
        );
        self::assertIsObject($row);
        self::assertFalse($row->is_on_sale);
        self::assertTrue($row->has_any_sale);

        self::assertContains(
            self::PRODUCT_EXTERNAL_ID,
            $this->repository->getAllProductsWithSaleStateDrift($this->saleCategoryId),
        );

        self::assertSame([self::PRODUCT_EXTERNAL_ID], $this->paginateFixtureSku(hasAnySale: true));
        self::assertSame([], $this->paginateFixtureSku(hasAnySale: false));
    }

    /**
     * @return list<int>
     */
    private function paginateFixtureSku(bool $hasAnySale): array
    {
        $page = $this->repository->paginate(new ProductListQueryParams(
            pagination: PageRequest::from(1, 10),
            filters: [
                ProductFilterField::Sku->value => self::PRODUCT_SKU,
                ProductFilterField::HasAnySale->value => $hasAnySale,
            ],
        ));

        return \array_map(static fn(ProductView $view): int => $view->id->value, $page->items);
    }

    /**
     * @return list<int>
     */
    private function externalIds(string $sql): array
    {
        return \array_map(
            static fn(object $row): int => (int) $row->external_id,
            DB::select($sql, ['[' . $this->saleCategoryId . ']']),
        );
    }

    private function insertInheritanceOnlyFixture(): void
    {
        self::assertFalse(
            DB::table('shopwired.products')->where('external_id', self::PRODUCT_EXTERNAL_ID)->exists(),
            'Sentinel product external_id already exists',
        );
        self::assertFalse(
            DB::table('shopwired.product_variations')->where('external_id', self::VARIATION_EXTERNAL_ID)->exists(),
            'Sentinel variation external_id already exists',
        );

        $now = \now();
        $productId = DB::table('shopwired.products')->insertGetId([
            'external_id' => self::PRODUCT_EXTERNAL_ID,
            'sku' => self::PRODUCT_SKU,
            'title' => 'Guard fixture: inheritance-only sale',
            'slug' => 'guard-has-any-sale-' . self::PRODUCT_EXTERNAL_ID,
            'url' => '/guard-has-any-sale-' . self::PRODUCT_EXTERNAL_ID,
            'price' => 10,
            'sale_price' => 12,
            'is_active' => true,
            'vat_exclusive' => false,
            'category_ids' => \json_encode([]),
            'images' => \json_encode([]),
            'custom_fields' => \json_encode((object) []),
            'filters' => \json_encode((object) []),
            'shopwired_created_at' => $now,
            'shopwired_updated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('shopwired.product_variations')->insert([
            'product_id' => $productId,
            'product_external_id' => self::PRODUCT_EXTERNAL_ID,
            'external_id' => self::VARIATION_EXTERNAL_ID,
            'price' => 15,
            'sale_price' => null,
            'stock' => 0,
            'options' => \json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
