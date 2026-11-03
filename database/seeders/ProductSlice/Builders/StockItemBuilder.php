<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

use Database\Factories\ProductSlice\StockItemRowFactory;
use Database\Seeders\ProductSlice\SeedProfile;
use UnexpectedValueException;

/**
 * One stock item per stocked catalog SKU (item numbers stay unique: a duplicate would duplicate a products-view row),
 * then SKU-less orphans carrying the archived, deleted, JIT, composite and zero-price flags.
 */
final readonly class StockItemBuilder
{
    public function __construct(
        private SeedRandom $random,
    ) {}

    public function build(SeedProfile $profile, Catalog $catalog): StockLedger
    {
        $factory = StockItemRowFactory::new();
        $rows = [];

        foreach ($catalog->stockedSkus as $sku) {
            $rows[] = $this->row($factory, [
                'item_number' => $sku->sku,
                'item_title' => $sku->title,
                'purchase_price' => $this->random->money(50, 20_000),
            ]);
        }

        foreach ($this->orphanOverrides($profile, \count($catalog->stockedSkus)) as $overrides) {
            $rows[] = $this->row($factory, $overrides);
        }

        return new StockLedger($rows, self::stockItemIds($rows), \count($catalog->stockedSkus));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orphanOverrides(SeedProfile $profile, int $matchedCount): array
    {
        $orphanCount = $profile->int('counts.stock_items') - $matchedCount;

        if ($orphanCount < 1) {
            throw new UnexpectedValueException('Seed profile leaves no stock item without a catalog SKU');
        }

        $orphans = \range(0, $orphanCount - 1);
        $archived = $this->random->subset($orphans, $profile->int('stock_items.is_archived'));
        $deleted = $this->random->subset($orphans, $profile->int('stock_items.is_logically_deleted'));
        $jit = $this->random->subset($orphans, $profile->int('stock_items.jit'));
        $composite = $this->random->subset($orphans, $profile->int('stock_items.is_composite'));
        $zeroPrice = $this->random->subset($orphans, $profile->int('stock_items.purchase_price_zero'));
        $emptyItemNumber = $this->random->subset($orphans, $profile->int('stock_items.item_number_null_or_empty'));
        $overrides = [];

        foreach ($orphans as $orphan) {
            $overrides[] = [
                'item_number' => isset($emptyItemNumber[$orphan]) ? '' : SyntheticCodes::orphanStockSku($orphan),
                'purchase_price' => isset($zeroPrice[$orphan]) ? 0.0 : $this->random->money(50, 20_000),
                'is_archived' => isset($archived[$orphan]),
                'is_logically_deleted' => isset($deleted[$orphan]),
                'jit' => isset($jit[$orphan]),
                'is_composite' => isset($composite[$orphan]),
            ];
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(StockItemRowFactory $factory, array $overrides): array
    {
        $timestamp = $this->random->timestamp();

        return $factory->row([
            ...$overrides,
            'retail_price' => $this->random->money(199, 49_999),
            'linnworks_created_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private static function stockItemIds(array $rows): array
    {
        return \array_map(
            static fn(array $row): string => \is_string($row['stock_item_id'] ?? null) ? $row['stock_item_id'] : '',
            $rows,
        );
    }
}
