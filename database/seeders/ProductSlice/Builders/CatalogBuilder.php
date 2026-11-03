<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

use Database\Seeders\ProductSlice\SeedIds;
use Database\Seeders\ProductSlice\SeedProfile;

final readonly class CatalogBuilder
{
    public function __construct(
        private SeedRandom $random,
    ) {}

    public function build(SeedProfile $profile, ProductRoles $roles, CategoryTree $categories): Catalog
    {
        $variationRoles = new VariationRolesBuilder($this->random)->build($profile, $roles);
        $products = new ProductRowBuilder($this->random, $roles, $categories);
        $variations = new VariationRowBuilder($this->random, $variationRoles);
        $collector = new CatalogCollector();
        [$firstOfPair, $secondOfPair] = $roles->duplicateTitle;
        $pairTitle = null;

        for ($position = 0; $position < $roles->count(); ++$position) {
            $product = $products->row($position, $position === $secondOfPair ? $pairTitle : null);
            $pairTitle = $position === $firstOfPair ? self::title($product) : $pairTitle;
            $collector->addProduct($product, self::productSku($products, $roles, $position, $product));

            $count = $roles->variationCounts[$position] ?? 0;
            $variationRows = $variations->rows($product, $position, $count);
            $first = $variationRoles->offsets[$position] ?? 0;

            foreach ($variationRows as $index => $variation) {
                $collector->addVariation($variation, self::variationSku($variations, $variationRoles, $first + $index, $product));
            }
        }

        return $collector->catalog();
    }

    /**
     * @param array<string, mixed> $product
     *
     * @return array{CatalogSku, bool}|null
     */
    private static function productSku(ProductRowBuilder $products, ProductRoles $roles, int $position, array $product): ?array
    {
        $sku = $products->sku($position);

        if ($sku === null) {
            return null;
        }

        return [
            new CatalogSku(\mb_trim($sku), SeedIds::PRODUCT_BASE + $position, null, self::title($product), isset($roles->active[$position])),
            isset($roles->stockedSku[$position]),
        ];
    }

    /**
     * @param array<string, mixed> $product
     *
     * @return array{CatalogSku, bool}|null
     */
    private static function variationSku(VariationRowBuilder $variations, VariationRoles $roles, int $position, array $product): ?array
    {
        $sku = $variations->sku($position);

        if ($sku === null) {
            return null;
        }

        $parentExternalId = $product['external_id'] ?? null;
        $isActive = $product['is_active'] ?? null;

        return [
            new CatalogSku($sku, \is_int($parentExternalId) ? $parentExternalId : 0, SeedIds::VARIATION_BASE + $position, self::title($product), $isActive === true),
            isset($roles->stocked[$position]),
        ];
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function title(array $product): string
    {
        $title = $product['title'] ?? null;

        return \is_string($title) ? $title : '';
    }
}
