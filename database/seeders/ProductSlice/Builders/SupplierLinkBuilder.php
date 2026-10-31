<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

use Database\Factories\ProductSlice\StockItemSupplierRowFactory;
use Database\Factories\ProductSlice\SupplierRowFactory;
use Database\Seeders\ProductSlice\SeedProfile;
use UnexpectedValueException;

/**
 * Links SKU-matched stock items first, gives each linked stock item distinct suppliers, and marks at most one
 * default link per stock item (a second default would duplicate its products-view row).
 */
final readonly class SupplierLinkBuilder
{
    public function __construct(
        private SeedRandom $random,
    ) {}

    public function build(SeedProfile $profile, StockLedger $ledger): SupplierNetwork
    {
        $suppliers = $this->suppliers($profile->int('counts.suppliers'));
        $capacities = SupplierCapacities::fromBuckets(
            $profile->histogram('stock_item_suppliers.links_per_supplier'),
            $profile->int('counts.stock_item_suppliers'),
        );
        $linksPerItem = $this->random->shuffle(SeedRandom::expand(\array_filter(
            $profile->intHistogram('stock_items.suppliers_per_stock_item'),
            static fn(int $bucket): bool => $bucket > 0,
            \ARRAY_FILTER_USE_KEY,
        )));

        if (\count($linksPerItem) > \count($ledger->stockItemIds) || \count($capacities) > \count($suppliers)) {
            throw new UnexpectedValueException('Seed profile links more stock items or suppliers than it seeds');
        }

        $assignment = SupplierAssignment::assign($linksPerItem, $capacities);
        $withoutDefault = $this->withoutDefault($profile, $linksPerItem, $ledger->matchedCount);
        $links = $this->links($profile, $ledger, $suppliers, $assignment, $withoutDefault);

        return new SupplierNetwork(
            \array_column($suppliers, 'row'),
            $links,
            \array_map(
                static fn(array $supplier): array => ['id' => $supplier['id'], 'name' => $supplier['name']],
                \array_slice($suppliers, 0, \count($capacities)),
            ),
        );
    }

    /**
     * @return list<array{id: string, name: string, row: array<string, mixed>}>
     */
    private function suppliers(int $count): array
    {
        $factory = SupplierRowFactory::new();
        $suppliers = [];

        for ($index = 0; $index < $count; ++$index) {
            $timestamp = $this->random->timestamp();
            $row = $factory->row(['created_at' => $timestamp, 'updated_at' => $timestamp]);
            $suppliers[] = [
                'id' => self::stringColumn($row, 'pk_supplier_id'),
                'name' => self::stringColumn($row, 'supplier_name'),
                'row' => $row,
            ];
        }

        return $suppliers;
    }

    /**
     * Single-link orphan stock items whose only link is not the default.
     *
     * @param list<int> $linksPerItem
     *
     * @return array<int, true>
     */
    private function withoutDefault(SeedProfile $profile, array $linksPerItem, int $matchedCount): array
    {
        $candidates = \array_keys(\array_filter(
            $linksPerItem,
            static fn(int $links, int $item): bool => $links === 1 && $item >= $matchedCount,
            \ARRAY_FILTER_USE_BOTH,
        ));

        return $this->random->subset($candidates, \count($linksPerItem) - $profile->int('stock_item_suppliers.is_default'));
    }

    /**
     * @param list<array{id: string, name: string, row: array<string, mixed>}> $suppliers
     * @param list<list<int>> $assignment supplier indexes per linked stock item
     * @param array<int, true> $withoutDefault
     *
     * @return list<array<string, mixed>>
     */
    private function links(SeedProfile $profile, StockLedger $ledger, array $suppliers, array $assignment, array $withoutDefault): array
    {
        $linkCount = \array_sum(\array_map(\count(...), $assignment));
        $allLinks = $linkCount > 0 ? \range(0, $linkCount - 1) : [];
        $zeroPrice = $this->random->subset($allLinks, $profile->int('stock_item_suppliers.purchase_price_zero'));
        $usd = $this->random->subset($allLinks, $profile->int('stock_item_suppliers.supplier_currency.USD'));
        $factory = StockItemSupplierRowFactory::new();
        $links = [];

        foreach ($assignment as $item => $supplierIndexes) {
            foreach ($supplierIndexes as $rank => $supplierIndex) {
                $link = \count($links);
                $links[] = $this->linkRow(
                    $factory,
                    $ledger->stockItemIds[$item] ?? '',
                    $suppliers[$supplierIndex] ?? throw new UnexpectedValueException('Supplier assignment is out of range'),
                    $rank === 0 && !isset($withoutDefault[$item]),
                    isset($zeroPrice[$link]),
                    isset($usd[$link]),
                );
            }
        }

        return $links;
    }

    /**
     * @param array{id: string, name: string, row: array<string, mixed>} $supplier
     *
     * @return array<string, mixed>
     */
    private function linkRow(StockItemSupplierRowFactory $factory, string $stockItemId, array $supplier, bool $isDefault, bool $zeroPrice, bool $usd): array
    {
        $timestamp = $this->random->timestamp();

        return $factory->row([
            'stock_item_id' => $stockItemId,
            'supplier_id' => $supplier['id'],
            'supplier_name' => $supplier['name'],
            'purchase_price' => $zeroPrice ? 0.0 : $this->random->money(50, 20_000),
            'is_default' => $isDefault,
            'supplier_currency' => $usd ? 'USD' : 'GBP',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function stringColumn(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        return \is_string($value) ? $value : '';
    }
}
