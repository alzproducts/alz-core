<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

use Database\Factories\ProductSlice\ProductVariationRowFactory;
use Database\Seeders\ProductSlice\SeedIds;

final readonly class VariationRowBuilder
{
    private const array OPTION_NAMES = ['Colour', 'Size', 'Style'];

    private const int GTIN_PREFIX = 51;

    private ProductVariationRowFactory $factory;

    public function __construct(
        private SeedRandom $random,
        private VariationRoles $roles,
    ) {
        $this->factory = ProductVariationRowFactory::new();
    }

    /**
     * @param array<string, mixed> $product the parent's generated row
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $product, int $productPosition, int $count): array
    {
        $first = $this->roles->offsets[$productPosition] ?? 0;
        $optionCount = self::optionCount($count);
        $rows = [];

        for ($index = 0; $index < $count; ++$index) {
            $rows[] = $this->row($product, $productPosition, $first + $index, self::options($productPosition, $first + $index, $index, $optionCount));
        }

        return $rows;
    }

    public function sku(int $position): ?string
    {
        return isset($this->roles->nullSku[$position]) ? null : SyntheticCodes::variationSku($position);
    }

    /**
     * Larger variation sets vary on more options, as in the profile's distinct-options distribution.
     */
    public static function optionCount(int $variationCount): int
    {
        if ($variationCount >= 12) {
            return 3;
        }

        return $variationCount >= 4 ? 2 : 1;
    }

    /**
     * @param array<string, mixed> $product
     * @param list<array{option_id: int, option_name: string, value_id: int, value_name: string}> $options
     *
     * @return array<string, mixed>
     */
    private function row(array $product, int $productPosition, int $position, array $options): array
    {
        $price = isset($this->roles->zeroPrice[$position]) ? 0.0 : $this->random->money(199, 49_999);
        $timestamp = $this->random->timestamp();

        return $this->factory->row([
            'product_id' => $product['id'] ?? null,
            'product_external_id' => SeedIds::PRODUCT_BASE + $productPosition,
            'external_id' => SeedIds::VARIATION_BASE + $position,
            'sku' => $this->sku($position),
            'price' => $price,
            'cost_price' => isset($this->roles->zeroCost[$position]) ? 0.0 : $this->random->money(50, 20_000),
            'sale_price' => isset($this->roles->onSale[$position]) ? \round($price * 0.8, 2) : 0.0,
            'gtin' => isset($this->roles->gtin[$position]) ? SyntheticCodes::gtin13(self::GTIN_PREFIX, $position) : null,
            'options' => $options,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /**
     * @return list<array{option_id: int, option_name: string, value_id: int, value_name: string}>
     */
    private static function options(int $productPosition, int $position, int $index, int $optionCount): array
    {
        $options = [];

        foreach (\array_slice(self::OPTION_NAMES, 0, $optionCount) as $optionIndex => $name) {
            $options[] = [
                'option_id' => SeedIds::OPTION_BASE + $productPosition * 3 + $optionIndex,
                'option_name' => $name,
                'value_id' => SeedIds::OPTION_VALUE_BASE + $position * 3 + $optionIndex,
                'value_name' => $optionIndex === 0 ? $name . ' ' . ($index + 1) : $name . ' ' . \chr(65 + $optionIndex),
            ];
        }

        return $options;
    }
}
