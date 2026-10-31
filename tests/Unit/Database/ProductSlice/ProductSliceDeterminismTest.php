<?php

declare(strict_types=1);

namespace Tests\Unit\Database\ProductSlice;

use Database\Seeders\ProductSlice\SliceRows;
use Database\Seeders\ProductSlice\SliceTables;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One seed fixes every row of every slice table; another seed changes them (AC4). No database is touched.
 */
#[CoversNothing]
final class ProductSliceDeterminismTest extends TestCase
{
    #[Test]
    public function the_same_seed_yields_identical_row_hashes_for_every_table(): void
    {
        $first = self::hashes(SliceFixture::rows());
        $second = self::hashes(SliceFixture::generate());

        $this->assertSame(SliceTables::INSERT_ORDER, \array_keys($first));
        $this->assertSame($first, $second);
    }

    #[Test]
    public function a_different_seed_yields_different_row_hashes_for_every_populated_table(): void
    {
        $base = self::hashes(SliceFixture::rows());
        $other = self::hashes(SliceFixture::rows(SliceFixture::SEED + 1));

        foreach (SliceTables::INSERT_ORDER as $table) {
            if ($table === SliceTables::STOCK_ITEM_EXTENDED_PROPERTIES) {
                $this->assertSame($base[$table], $other[$table], 'the slice seeds no extended properties');

                continue;
            }

            $this->assertNotSame($base[$table], $other[$table], $table . ' must change with the seed');
        }
    }

    /**
     * @return array<string, string>
     */
    private static function hashes(SliceRows $slice): array
    {
        return \array_map(
            static fn(array $rows): string => \hash('sha256', \json_encode($rows, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)),
            $slice->tables,
        );
    }
}
