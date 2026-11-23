<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Catalog\Repositories;

use App\Domain\Catalog\RelatedProducts\ValueObjects\RelatedProductsAlgorithmParams;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Catalog\Repositories\RelatedProductsQueryRepository;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Candidate pruning at its bound. Every fixture product has a category of its own, so no pair shares
 * one and only title similarity can carry a pair past min_content_score. With the default weights the
 * bound is 0.1 / 0.35 = 0.2857: "Zyqvorth Blenmax" ~ "Zyqvorth Kwipdalosunte" scores 0.2903 and
 * "Fryzmuth Okvalde" ~ "Fryzmuth Gastrenivoplac" scores 0.2813. "Pucklejig" shares no trigram with
 * "Zyqvorth Blenmax". Fixture popularity outranks every real product, whose snapshots predate the
 * fixtures' and so fall back to default_popularity.
 */
#[CoversClass(RelatedProductsQueryRepository::class)]
final class RelatedProductsQueryRepositoryTest extends TestCase
{
    private const int BASE = 2147480500;

    private const int ABOVE = 0;

    private const int ABOVE_PARTNER = 1;

    private const int BELOW = 2;

    private const int BELOW_PARTNER = 3;

    private const int NO_TRIGRAM = 4;

    private const array TITLES = [
        self::ABOVE => 'Zyqvorth Blenmax',
        self::ABOVE_PARTNER => 'Zyqvorth Kwipdalosunte',
        self::BELOW => 'Fryzmuth Okvalde',
        self::BELOW_PARTNER => 'Fryzmuth Gastrenivoplac',
        self::NO_TRIGRAM => 'Pucklejig',
    ];

    /** Later than any real snapshot, so the fixtures' rows are the only "latest" popularity. */
    private const string SNAPSHOT_DATE = '2099-12-31';

    private const string FIXED_TIME = '2026-10-01 09:30:00+00';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        DB::setDefaultConnection('pgsql');
        // intentional: no DB trait — under --parallel it swaps to a per-worker database; a plain transaction keeps the fixtures private
        DB::beginTransaction();
        $this->insertFixtures();
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    #[Test]
    public function a_pair_sharing_no_category_is_returned_only_when_its_title_similarity_reaches_the_bound(): void
    {
        $related = $this->computeRelatedProducts(minContentScore: 0.1);

        self::assertSame([self::productId(self::ABOVE_PARTNER)], $related[self::productId(self::ABOVE)] ?? []);
        self::assertSame([], $related[self::productId(self::BELOW)] ?? []);
    }

    #[Test]
    public function a_zero_min_content_score_still_ranks_a_pair_sharing_no_category_and_no_trigram(): void
    {
        $related = $this->computeRelatedProducts(minContentScore: 0.0);

        self::assertContains(self::productId(self::NO_TRIGRAM), $related[self::productId(self::ABOVE)] ?? []);
    }

    /**
     * @return array<int, list<int>>
     */
    private function computeRelatedProducts(float $minContentScore): array
    {
        $params = new RelatedProductsAlgorithmParams(
            categoryWeight: 0.45,
            titleWeight: 0.35,
            popularityWeight: 0.2,
            maxResults: 20,
            minContentScore: $minContentScore,
            defaultPopularity: 0.01,
            excludeCompareList: true,
        );
        $map = $this->app->make(RelatedProductsQueryRepository::class)->computeRelatedProducts($params);

        return \array_map(
            static fn(array $ids): array => \array_map(static fn(IntId $id): int => $id->value, $ids),
            $map,
        );
    }

    private function insertFixtures(): void
    {
        $scores = ['main_qty' => 0, 'main_turnover' => 0, 'recent_qty' => 0, 'recent_turnover' => 0, 'main_qty_rank' => 0, 'main_turnover_rank' => 0, 'recent_qty_rank' => 0, 'recent_turnover_rank' => 0, 'main_score' => 0, 'recent_score' => 0, 'trend' => 0];

        foreach (self::TITLES as $n => $title) {
            DB::table('shopwired.categories')->insert([
                'external_id' => self::categoryId($n),
                'shopwired_created_at' => self::FIXED_TIME,
                'title' => "Rpqr category {$n}",
                'slug' => "rpqr-c{$n}",
                'url' => "/rpqr-c{$n}",
                'active' => true,
                'featured' => false,
                'trade_only' => false,
                'sort_order' => $n,
                'meta_no_index' => false,
                'parent_ids' => '[]',
                'custom_fields' => '{}',
                'created_at' => self::FIXED_TIME,
                'updated_at' => self::FIXED_TIME,
            ]);
            DB::table('shopwired.products')->insert([
                'id' => \sprintf('%08x-0000-4000-8000-%012x', self::BASE, $n),
                'external_id' => self::productId($n),
                'sku' => "RPQR-P{$n}",
                'title' => $title,
                'description' => "<p>{$title}</p>",
                'slug' => "rpqr-p{$n}",
                'url' => "/rpqr-p{$n}",
                'price' => '10.00',
                'stock' => 5,
                'is_active' => true,
                'vat_exclusive' => false,
                'category_ids' => \json_encode([self::categoryId($n)]),
                'images' => '[]',
                'custom_fields' => '{}',
                'filters' => '{}',
                'shopwired_created_at' => self::FIXED_TIME,
                'shopwired_updated_at' => self::FIXED_TIME,
                'created_at' => self::FIXED_TIME,
                'updated_at' => self::FIXED_TIME,
            ]);
            DB::table('catalog.product_popularity_snapshots')->insert([
                'snapshot_date' => self::SNAPSHOT_DATE, 'algorithm_version' => 1, 'parent_external_id' => self::productId($n),
                'calculated_sort_order' => $n + 1, ...$scores, 'final_score' => '9.50',
            ]);
        }
    }

    private static function productId(int $n): int
    {
        return self::BASE + $n;
    }

    private static function categoryId(int $n): int
    {
        return self::BASE + 90 + $n;
    }
}
