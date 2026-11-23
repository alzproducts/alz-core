<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

/**
 * One order line. `price` and `priceVat` are per unit; `skuOverride` writes an `order_product_extra_data` row.
 */
final readonly class LineSpec
{
    public function __construct(
        public string $sku,
        public float $price,
        public float $priceVat,
        public int $vatRate,
        public int $quantity = 1,
        public ?string $skuOverride = null,
    ) {}

    public static function standard(string $sku, float $price, int $quantity = 1): self
    {
        return new self($sku, $price, \round($price * 0.2, 2), 20, $quantity);
    }

    public static function zeroRated(string $sku, float $price, int $quantity = 1): self
    {
        return new self($sku, $price, 0.0, 0, $quantity);
    }
}
