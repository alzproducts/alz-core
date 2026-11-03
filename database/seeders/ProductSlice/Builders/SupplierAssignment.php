<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

use UnexpectedValueException;

/**
 * Deals each stock item its links from the suppliers with the most capacity left, widest stock items first,
 * so every stock item's suppliers are distinct and every supplier's capacity is used exactly.
 */
final class SupplierAssignment
{
    /**
     * @param list<int> $linksPerItem
     * @param list<int> $capacities
     *
     * @return list<list<int>> supplier indexes per stock item, in `$linksPerItem` order
     */
    public static function assign(array $linksPerItem, array $capacities): array
    {
        if (\array_sum($linksPerItem) !== \array_sum($capacities)) {
            throw new UnexpectedValueException('Seed profile supplier links do not match per stock item and per supplier');
        }

        $remaining = $capacities;
        $assigned = [];

        foreach (self::widestFirst($linksPerItem) as [$item, $links]) {
            [$assigned[$item], $remaining] = self::take($remaining, $links);
        }

        return \array_map(static fn(int $item): array => $assigned[$item] ?? [], \array_keys($linksPerItem));
    }

    /**
     * @param array<int, int> $remaining links left per supplier index
     *
     * @return array{list<int>, array<int, int>} the chosen supplier indexes, and the links left after them
     */
    private static function take(array $remaining, int $links): array
    {
        \arsort($remaining);
        $chosen = [];

        foreach ($remaining as $supplier => $left) {
            if (\count($chosen) === $links) {
                break;
            }

            if ($left > 0) {
                $chosen[] = $supplier;
                $remaining[$supplier] = $left - 1;
            }
        }

        if (\count($chosen) !== $links) {
            throw new UnexpectedValueException('Seed profile asks for more distinct suppliers than have links left');
        }

        return [$chosen, $remaining];
    }

    /**
     * @param list<int> $linksPerItem
     *
     * @return list<array{int, int}> `[item, links]`, most links first, then by item
     */
    private static function widestFirst(array $linksPerItem): array
    {
        $items = \array_map(static fn(int $links, int $item): array => [$item, $links], $linksPerItem, \array_keys($linksPerItem));
        \usort($items, static fn(array $a, array $b): int => [$b[1], $a[0]] <=> [$a[1], $b[0]]);

        return $items;
    }
}
