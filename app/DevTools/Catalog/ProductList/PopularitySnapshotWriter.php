<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use App\Infrastructure\Catalog\Repositories\ProductPopularityRankingSnapshotRepository;
use App\Infrastructure\Catalog\Repositories\SkuPopularityRankingSnapshotRepository;
use App\Infrastructure\Database\DatabaseGateway;
use RuntimeException;

/**
 * The weekly product and SKU popularity snapshots, written for today. Today's rows become the latest snapshot the
 * list reads, so the writer runs only when today has none yet and deletes them again on restore.
 */
final class PopularitySnapshotWriter
{
    private const array TABLES = ['catalog.product_popularity_snapshots', 'catalog.sku_popularity_snapshots'];

    private bool $todayWasEmpty = false;

    public function __construct(
        private readonly ProductPopularityRankingSnapshotRepository $productSnapshots,
        private readonly SkuPopularityRankingSnapshotRepository $skuSnapshots,
        private readonly DatabaseGateway $database,
    ) {}

    /**
     * @throws RuntimeException
     */
    public function captureBaseline(): bool
    {
        $rows = 0;

        foreach (self::TABLES as $table) {
            $count = $this->database->query(
                fn(): mixed => $this->database->connection()->scalar("SELECT count(*) FROM {$table} WHERE snapshot_date = CURRENT_DATE"),
            );
            $rows += \is_int($count) ? $count : throw new RuntimeException('Could not count today\'s popularity snapshots.');
        }

        $this->todayWasEmpty = $rows === 0;

        return $this->todayWasEmpty;
    }

    /**
     * @throws RuntimeException
     */
    public function write(): void
    {
        if ($this->todayWasEmpty) {
            $this->productSnapshots->writeSnapshotForToday();
            $this->skuSnapshots->writeSnapshotForToday();
        }
    }

    /**
     * @throws RuntimeException
     */
    public function restore(): void
    {
        if (! $this->todayWasEmpty) {
            return;
        }

        foreach (self::TABLES as $table) {
            $this->database->runSql("DELETE FROM {$table} WHERE snapshot_date = CURRENT_DATE");
        }
    }
}
