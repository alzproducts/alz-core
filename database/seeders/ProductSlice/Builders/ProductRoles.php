<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

/**
 * Which product (by position) plays which structural role. Sets are `position => true`.
 */
final readonly class ProductRoles
{
    /**
     * @param list<int> $variationCounts
     * @param array<int, true> $nullSku
     * @param array<int, true> $stockedSku
     * @param array<int, true> $masterSale
     * @param array<int, true> $variationSale
     * @param array<int, true> $zeroPrice
     * @param array<int, true> $overTwoDecimals
     * @param array<int, true> $zeroCost
     * @param array<int, true> $comparePrice
     * @param array<int, true> $vatExclusive
     * @param array<int, true> $vatRelief
     * @param array<int, true> $active
     * @param array<int, true> $standardDelivery
     * @param array<int, true> $expressDelivery
     * @param array<int, true> $bestBuy
     * @param array<int, true> $dimensions
     * @param array<int, true> $gtin
     * @param list<int> $imageCounts
     * @param list<int> $categoryCounts
     * @param array{int, int} $duplicateTitle
     */
    public function __construct(
        public array $variationCounts,
        public array $nullSku,
        public array $stockedSku,
        public int $whitespaceSku,
        public array $masterSale,
        public array $variationSale,
        public int $saleVariationsPerProduct,
        public array $zeroPrice,
        public array $overTwoDecimals,
        public array $zeroCost,
        public array $comparePrice,
        public array $vatExclusive,
        public array $vatRelief,
        public array $active,
        public array $standardDelivery,
        public array $expressDelivery,
        public array $bestBuy,
        public array $dimensions,
        public array $gtin,
        public int $whitespaceGtin,
        public array $imageCounts,
        public array $categoryCounts,
        public array $duplicateTitle,
    ) {}

    public function count(): int
    {
        return \count($this->variationCounts);
    }

    public function hasAnySale(int $position): bool
    {
        return isset($this->masterSale[$position]) || isset($this->variationSale[$position]);
    }

    public function freeDelivery(int $position): ?string
    {
        if (isset($this->standardDelivery[$position])) {
            return 'Standard';
        }

        return isset($this->expressDelivery[$position]) ? 'Express' : null;
    }
}
