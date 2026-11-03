<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

/**
 * SKU and barcode formats. Each owner gets its own SKU prefix, so product and variation SKUs never collide
 * (a trigger enforces SKU uniqueness across both tables).
 */
final class SyntheticCodes
{
    public static function productSku(int $position): string
    {
        return \sprintf('SP%06d', $position);
    }

    public static function variationSku(int $position): string
    {
        return \sprintf('SV%06d', $position);
    }

    public static function orphanStockSku(int $position): string
    {
        return \sprintf('SO%06d', $position);
    }

    public static function orphanExtraDataSku(int $position): string
    {
        return \sprintf('SX%06d', $position);
    }

    /**
     * A GTIN-13 with a valid GS1 check digit.
     */
    public static function gtin13(int $prefix, int $position): string
    {
        $body = \sprintf('%02d%010d', $prefix, $position);
        $sum = 0;

        foreach (\mb_str_split($body) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return $body . (10 - $sum % 10) % 10;
    }
}
