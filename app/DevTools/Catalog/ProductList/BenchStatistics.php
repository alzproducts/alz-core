<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use InvalidArgumentException;

final class BenchStatistics
{
    /**
     * @param list<float> $values
     */
    public static function median(array $values): float
    {
        return self::percentile($values, 50.0);
    }

    /**
     * Nearest-rank percentile.
     *
     * @param list<float> $values
     */
    public static function percentile(array $values, float $percent): float
    {
        if ($values === []) {
            throw new InvalidArgumentException('A percentile needs at least one value.');
        }

        \sort($values);
        $rank = (int) \ceil($percent / 100 * \count($values));

        return $values[\max($rank, 1) - 1] ?? \max($values);
    }

    public static function elapsedMilliseconds(float $startedAt): float
    {
        return \round((\hrtime(true) / 1e6) - $startedAt, 1);
    }

    public static function nowMilliseconds(): float
    {
        return \hrtime(true) / 1e6;
    }
}
