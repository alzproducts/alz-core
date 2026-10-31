<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice;

use Database\Seeders\ProductSlice\Builders\CatalogBuilder;
use Database\Seeders\ProductSlice\Builders\CategoryTreeBuilder;
use Database\Seeders\ProductSlice\Builders\CustomFieldDefinitionBuilder;
use Database\Seeders\ProductSlice\Builders\FilterGroupBuilder;
use Database\Seeders\ProductSlice\Builders\PopularityBuilder;
use Database\Seeders\ProductSlice\Builders\PricingBuilder;
use Database\Seeders\ProductSlice\Builders\ProductRolesBuilder;
use Database\Seeders\ProductSlice\Builders\SeedRandom;
use Database\Seeders\ProductSlice\Builders\StockItemBuilder;
use Database\Seeders\ProductSlice\Builders\SupplierLinkBuilder;
use Database\Seeders\ProductSlice\Contracts\SliceGeneratorInterface;
use Faker\Generator;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Override;

/**
 * Reproduces the profile's counts and distributions from one seed. The row factories draw from the container's
 * Faker generator, which this seeds once, so the seed fixes every column the factories fill too.
 */
final readonly class ProfileSliceGenerator implements SliceGeneratorInterface
{
    /**
     * @throws BindingResolutionException
     */
    #[Override]
    public function generate(SeedProfile $profile, int $seed, int $saleCategoryId): SliceRows
    {
        SeedIds::assertSaleCategorySlot($saleCategoryId);

        $faker = Container::getInstance()->make(Generator::class);
        $faker->seed($seed);
        $random = new SeedRandom($faker);

        $definitions = new CustomFieldDefinitionBuilder()->build($profile);
        $categories = new CategoryTreeBuilder($random)->build($profile, $saleCategoryId);
        $filterGroups = new FilterGroupBuilder()->build();
        $roles = new ProductRolesBuilder($random)->build($profile);
        $catalog = new CatalogBuilder($random)->build($profile, $roles, $categories);
        $stock = new StockItemBuilder($random)->build($profile, $catalog);
        $network = new SupplierLinkBuilder($random)->build($profile, $stock);
        $pricing = new PricingBuilder($random);
        $popularity = new PopularityBuilder($random);

        return new SliceRows([
            SliceTables::CUSTOM_FIELD_DEFINITIONS => $definitions,
            SliceTables::CATEGORIES => $categories->rows,
            SliceTables::FILTER_GROUPS => $filterGroups,
            SliceTables::PRODUCTS => $catalog->productRows,
            SliceTables::PRODUCT_VARIATIONS => $catalog->variationRows,
            SliceTables::STOCK_ITEMS => $stock->rows,
            SliceTables::STOCK_ITEM_EXTENDED_PROPERTIES => [],
            SliceTables::SUPPLIERS => $network->supplierRows,
            SliceTables::STOCK_ITEM_SUPPLIERS => $network->linkRows,
            SliceTables::PRODUCT_EXTRA_DATA => $pricing->extraData($profile, $catalog),
            SliceTables::PRICE_PERIODS => $pricing->pricePeriods($profile, $catalog),
            SliceTables::COST_PRICE_CHANGES => $pricing->costPriceChanges($profile, $catalog, $network->linkedSuppliers),
            SliceTables::PRODUCT_POPULARITY_SNAPSHOTS => $popularity->productSnapshots($profile, $catalog),
            SliceTables::SKU_POPULARITY_SNAPSHOTS => $popularity->skuSnapshots($profile, $catalog),
        ]);
    }
}
