<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

/**
 * Which variation (by catalog-wide position) plays which structural role. Sets are `position => true`.
 */
final readonly class VariationRoles
{
    /**
     * @param list<int> $offsets first variation position of each product
     * @param array<int, true> $nullSku
     * @param array<int, true> $stocked
     * @param array<int, true> $zeroPrice
     * @param array<int, true> $zeroCost
     * @param array<int, true> $gtin
     * @param array<int, true> $onSale
     */
    public function __construct(
        public array $offsets,
        public array $nullSku,
        public array $stocked,
        public array $zeroPrice,
        public array $zeroCost,
        public array $gtin,
        public array $onSale,
    ) {}
}
