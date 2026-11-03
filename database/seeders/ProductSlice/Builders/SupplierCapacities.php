<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

use UnexpectedValueException;

/**
 * Turns the profile's links-per-supplier buckets into one link capacity per linked supplier: exact buckets as is,
 * ranges at their midpoint, and the open-ended top bucket takes whatever the total leaves.
 */
final class SupplierCapacities
{
    /**
     * @param array<array-key, int> $buckets e.g. `[1 => 20, "11-25" => 8, "251+" => 1]`
     *
     * @return list<int>
     */
    public static function fromBuckets(array $buckets, int $totalLinks): array
    {
        $capacities = [];
        $openEnded = 0;

        foreach ($buckets as $label => $suppliers) {
            $capacity = self::capacity((string) $label);

            if ($capacity === null) {
                $openEnded += $suppliers;

                continue;
            }

            \array_push($capacities, ...\array_fill(0, $suppliers, $capacity));
        }

        return self::withRemainder($capacities, $openEnded, $totalLinks);
    }

    /**
     * @param list<int> $capacities
     *
     * @return list<int>
     */
    private static function withRemainder(array $capacities, int $openEnded, int $totalLinks): array
    {
        $remainder = $totalLinks - \array_sum($capacities);

        if ($openEnded !== 1 || $remainder < 1) {
            throw new UnexpectedValueException('Seed profile supplier buckets need exactly one open-ended supplier with links left over');
        }

        $capacities[] = $remainder;

        return $capacities;
    }

    private static function capacity(string $label): ?int
    {
        if (\preg_match('/^(\d+)-(\d+)$/', $label, $range) === 1) {
            return \intdiv((int) $range[1] + (int) $range[2], 2);
        }

        if (\preg_match('/^\d+$/', $label) === 1) {
            return (int) $label;
        }

        if (\preg_match('/^\d+\+$/', $label) === 1) {
            return null;
        }

        throw new UnexpectedValueException('Seed profile supplier bucket is not N, N-M or N+: ' . $label);
    }
}
