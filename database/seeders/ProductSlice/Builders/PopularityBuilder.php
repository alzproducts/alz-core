<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

use Database\Factories\ProductSlice\ProductPopularitySnapshotRowFactory;
use Database\Factories\ProductSlice\SkuPopularitySnapshotRowFactory;
use Database\Seeders\ProductSlice\SeedProfile;

/**
 * One snapshot date only: the `*_latest` views read the global latest date, and algorithm version 1 is the
 * migration-seeded config row the snapshots reference.
 */
final readonly class PopularityBuilder
{
    public const string SNAPSHOT_DATE = '2026-10-01';

    public const int ALGORITHM_VERSION = 1;

    /** The migration-seeded config rows' `max_rank`; the ranking views emit `calculated_sort_order` in 1..max_rank. */
    public const int MAX_RANK = 12;

    public function __construct(
        private SeedRandom $random,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function productSnapshots(SeedProfile $profile, Catalog $catalog): array
    {
        $factory = ProductPopularitySnapshotRowFactory::new();
        $products = \array_slice($catalog->productRows, 0, $profile->int('popularity.product_latest_rows'));
        $rows = [];

        foreach ($products as $product) {
            $rows[] = $factory->row([
                'snapshot_date' => self::SNAPSHOT_DATE,
                'algorithm_version' => self::ALGORITHM_VERSION,
                'parent_external_id' => $product['external_id'] ?? null,
                'sku' => $product['sku'] ?? null,
                'title' => $product['title'] ?? null,
                'is_active' => $product['is_active'] ?? null,
                'calculated_sort_order' => $this->random->int(1, self::MAX_RANK),
                'current_sort_order' => $product['sort_order'] ?? null,
            ]);
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function skuSnapshots(SeedProfile $profile, Catalog $catalog): array
    {
        $factory = SkuPopularitySnapshotRowFactory::new();
        $skus = $this->random->sample($catalog->skus, $profile->int('popularity.sku_latest_rows'));
        $rows = [];

        foreach ($skus as $sku) {
            $rows[] = $factory->row([
                'snapshot_date' => self::SNAPSHOT_DATE,
                'algorithm_version' => self::ALGORITHM_VERSION,
                'live_sku' => $sku->sku,
                'parent_external_id' => $sku->parentExternalId,
                'variation_external_id' => $sku->variationExternalId,
                'title' => $sku->title,
                'is_active' => $sku->isActive,
                'calculated_sort_order' => $this->random->int(1, self::MAX_RANK),
            ]);
        }

        return $rows;
    }
}
