<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\ProductListFreshness;

use App\Application\Catalog\UseCases\SnapshotProductPopularityRankingUseCase;
use App\Application\Catalog\UseCases\SnapshotSkuPopularityRankingUseCase;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * A weekly popularity snapshot moves the "latest" snapshot date, so it changes the popularity of
 * every listed product and variation at once, not just rows it names.
 *
 * The snapshot writes today's rows for every product in the database; tearDown deletes them so
 * the next run (and the local database) is left as it was.
 */
#[CoversNothing]
final class PopularitySnapshotWritersFreshnessTest extends ProductListFreshnessTestCase
{
    private const array SNAPSHOT_TABLES = ['catalog.product_popularity_snapshots', 'catalog.sku_popularity_snapshots'];

    private bool $wroteSnapshot = false;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::SNAPSHOT_TABLES as $table) {
            self::assertFalse(
                DB::table($table)->where('snapshot_date', \today()->toDateString())->exists(),
                "{$table} already has rows for today; this test would delete them",
            );
        }

        $productId = $this->insertProduct(0);
        $this->insertVariation($productId, 0, 0);
        $this->insertProduct(1);
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->wroteSnapshot) {
            foreach (self::SNAPSHOT_TABLES as $table) {
                DB::table($table)->where('snapshot_date', \today()->toDateString())->delete();
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function product_popularity_snapshot_is_listed_for_every_product_on_the_next_request(): void
    {
        [$before0, $fingerprint0] = $this->warm(0);
        [$before1, $fingerprint1] = $this->warm(1);
        self::assertNull($before0['popularity']);
        self::assertNull($before1['popularity']);

        $this->wroteSnapshot = true;
        $this->app->make(SnapshotProductPopularityRankingUseCase::class)->execute();

        $products = $this->listProducts();
        foreach ([0, 1] as $n) {
            $popularity = $products[self::productExternalId($n)]['popularity'] ?? null;
            self::assertIsArray($popularity, "Product {$n} must list today's popularity");
            self::assertIsInt($popularity['rank']);
        }
        self::assertNotSame($fingerprint0, $this->fragmentFingerprint(0));
        self::assertNotSame($fingerprint1, $this->fragmentFingerprint(1));
    }

    #[Test]
    public function sku_popularity_snapshot_is_listed_on_the_next_request(): void
    {
        [$before] = $this->warm(0);
        self::assertNull(self::listedVariation($before, 0)['popularity']);

        $this->wroteSnapshot = true;
        $this->app->make(SnapshotSkuPopularityRankingUseCase::class)->execute();

        $popularity = self::listedVariation($this->listedProduct(0), 0)['popularity'];
        self::assertIsArray($popularity);
        self::assertIsInt($popularity['rank']);
    }

    #[Override]
    protected static function idBase(): int
    {
        return 2147480500;
    }

    #[Override]
    protected static function skuPrefix(): string
    {
        return 'FRESH-POP-';
    }
}
