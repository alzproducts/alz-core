<?php

declare(strict_types=1);

namespace Tests\Unit\Database\ProductSlice;

use Database\Seeders\ProductSlice\ProfileSliceGenerator;
use Database\Seeders\ProductSlice\SeedProfile;
use Database\Seeders\ProductSlice\SliceRows;

/**
 * Generates the full slice in memory from the committed profile; generation costs seconds, so each
 * (seed, sale category) slice is built once per test process.
 */
final class SliceFixture
{
    public const int SEED = 20_261_030;

    public const int SALE_CATEGORY_ID = 2_000_000_001;

    /** @var array<string, SliceRows> */
    private static array $cache = [];

    public static function profile(): SeedProfile
    {
        return SeedProfile::fromFile();
    }

    public static function rows(int $seed = self::SEED, int $saleCategoryId = self::SALE_CATEGORY_ID): SliceRows
    {
        return self::$cache[$seed . ':' . $saleCategoryId] ??= self::generate($seed, $saleCategoryId);
    }

    public static function generate(int $seed = self::SEED, int $saleCategoryId = self::SALE_CATEGORY_ID): SliceRows
    {
        return new ProfileSliceGenerator()->generate(self::profile(), $seed, $saleCategoryId);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<mixed>
     */
    public static function column(array $rows, string $column): array
    {
        return \array_map(static fn(array $row): mixed => $row[$column] ?? null, $rows);
    }
}
