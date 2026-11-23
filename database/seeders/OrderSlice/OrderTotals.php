<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

/**
 * Order money derived from its lines, as ShopWired stores it. Refunds lower only `total`; the baseline
 * (`sub_total_net + shipping_total_net + tax_value`) is the unrefunded amount the profile measures refunds against.
 */
final class OrderTotals
{
    private const float STANDARD_RATE = 0.2;

    /**
     * @param list<LineSpec> $lines
     */
    public static function subTotalNet(array $lines): float
    {
        return \round(\array_sum(\array_map(static fn(LineSpec $line): float => $line->price * $line->quantity, $lines)), 3);
    }

    /**
     * @param list<LineSpec> $lines
     */
    public static function taxValue(array $lines, float $shippingNet): float
    {
        $lineVat = \array_sum(\array_map(static fn(LineSpec $line): float => $line->priceVat * $line->quantity, $lines));

        return \round($lineVat + self::shippingVat($lines, $shippingNet), 3);
    }

    /**
     * Shipping is standard-rated when any line is, else zero-rated.
     *
     * @param list<LineSpec> $lines
     */
    public static function shippingVatRate(array $lines): int
    {
        return \array_any($lines, static fn(LineSpec $line): bool => $line->vatRate > 0) ? 20 : 0;
    }

    /**
     * @param list<LineSpec> $lines
     */
    public static function baseline(array $lines, float $shippingNet): float
    {
        return \round(self::subTotalNet($lines) + $shippingNet + self::taxValue($lines, $shippingNet), 3);
    }

    /**
     * @param list<LineSpec> $lines
     * @param list<float> $refunds
     */
    public static function total(array $lines, float $shippingNet, array $refunds): float
    {
        return \round(self::baseline($lines, $shippingNet) - \array_sum($refunds), 3);
    }

    /**
     * @param list<LineSpec> $lines
     */
    private static function shippingVat(array $lines, float $shippingNet): float
    {
        return self::shippingVatRate($lines) > 0 ? \round($shippingNet * self::STANDARD_RATE, 2) : 0.0;
    }
}
