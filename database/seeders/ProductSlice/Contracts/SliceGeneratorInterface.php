<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Contracts;

use Database\Seeders\ProductSlice\SeedProfile;
use Database\Seeders\ProductSlice\SliceRows;
use Illuminate\Contracts\Container\BindingResolutionException;

interface SliceGeneratorInterface
{
    /**
     * @param int $saleCategoryId external ID the sale category is seeded under
     *
     * @throws BindingResolutionException
     */
    public function generate(SeedProfile $profile, int $seed, int $saleCategoryId): SliceRows;
}
