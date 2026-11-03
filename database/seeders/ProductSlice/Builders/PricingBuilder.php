<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

use Database\Factories\ProductSlice\CostPriceChangeRowFactory;
use Database\Factories\ProductSlice\PricePeriodRowFactory;
use Database\Factories\ProductSlice\ProductExtraDataRowFactory;
use Database\Seeders\ProductSlice\SeedProfile;

/**
 * Extra data, price history and cost-price changes, keyed by live catalog SKUs.
 */
final readonly class PricingBuilder
{
    private const int DAY = 86_400;

    public function __construct(
        private SeedRandom $random,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function extraData(SeedProfile $profile, Catalog $catalog): array
    {
        $factory = ProductExtraDataRowFactory::new();
        $matched = $profile->int('product_extra_data.sku_matches_catalog');
        $skus = \array_map(static fn(CatalogSku $sku): string => $sku->sku, $this->random->sample($catalog->skus, $matched));

        for ($orphan = 0; \count($skus) < $profile->int('counts.product_extra_data'); ++$orphan) {
            $skus[] = SyntheticCodes::orphanExtraDataSku($orphan);
        }

        return \array_map(function (string $sku) use ($factory): array {
            $timestamp = $this->random->timestamp();

            return $factory->row(['sku' => $sku, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
        }, $skus);
    }

    /**
     * Each SKU's periods chain end to start; only the latest is open, as the one-open-period-per-SKU index requires.
     *
     * @return list<array<string, mixed>>
     */
    public function pricePeriods(SeedProfile $profile, Catalog $catalog): array
    {
        $factory = PricePeriodRowFactory::new();
        $periodsPerSku = $this->random->shuffle(SeedRandom::expand($profile->intHistogram('price_periods.periods_per_sku')));
        $rows = [];

        foreach ($this->random->sample($catalog->skus, \count($periodsPerSku)) as $index => $sku) {
            $boundaries = $this->boundaries($periodsPerSku[$index] ?? 1);

            foreach ($boundaries as $period => $from) {
                $rows[] = $factory->row([
                    'sku' => $sku->sku,
                    ...$this->periodPrices(),
                    'effective_from' => SeedRandom::formatTimestamp($from),
                    'effective_to' => isset($boundaries[$period + 1]) ? SeedRandom::formatTimestamp($boundaries[$period + 1]) : null,
                    'created_at' => SeedRandom::formatTimestamp($from),
                ]);
            }
        }

        return $rows;
    }

    /**
     * @param list<array{id: string, name: string}> $linkedSuppliers
     *
     * @return list<array<string, mixed>>
     */
    public function costPriceChanges(SeedProfile $profile, Catalog $catalog, array $linkedSuppliers): array
    {
        $factory = CostPriceChangeRowFactory::new();
        $changesPerSku = $this->random->shuffle(SeedRandom::expand($profile->intHistogram('cost_price_changes.changes_per_sku')));
        $suppliers = $this->random->sample($linkedSuppliers, $profile->int('cost_price_changes.distinct_supplier_ids'));
        $rows = [];

        foreach ($this->random->sample($catalog->skus, \count($changesPerSku)) as $index => $sku) {
            $supplier = $this->random->pick($suppliers);
            $cost = $this->random->money(50, 20_000);

            foreach ($this->boundaries($changesPerSku[$index] ?? 1) as $changedAt) {
                $newCost = $this->random->money(50, 20_000);
                $rows[] = $factory->row([
                    'sku' => $sku->sku,
                    'supplier_id' => $supplier['id'],
                    'supplier_name' => $supplier['name'],
                    'old_cost_price' => $cost,
                    'new_cost_price' => $newCost,
                    'changed_at' => SeedRandom::formatTimestamp($changedAt),
                ]);
                $cost = $newCost;
            }
        }

        return $rows;
    }

    /**
     * @return list<int> ascending unix timestamps
     */
    private function boundaries(int $count): array
    {
        $boundaries = [SeedRandom::BASE_TIMESTAMP + $this->random->int(0, 60 * self::DAY)];

        while (\count($boundaries) < $count) {
            $boundaries[] = $boundaries[\count($boundaries) - 1] + $this->random->int(7 * self::DAY, 60 * self::DAY);
        }

        return $boundaries;
    }

    /**
     * @return array{base_price_gross: float, sale_price_gross: float|null, effective_price_gross: float}
     */
    private function periodPrices(): array
    {
        $base = $this->random->money(199, 49_999);
        $sale = $this->random->int(1, 10) === 1 ? \round($base * 0.8, 2) : null;

        return [
            'base_price_gross' => $base,
            'sale_price_gross' => $sale,
            'effective_price_gross' => $sale ?? $base,
        ];
    }
}
