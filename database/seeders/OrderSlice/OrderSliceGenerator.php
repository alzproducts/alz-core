<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

use Database\Seeders\ProductSlice\Builders\SeedRandom;
use Database\Seeders\ProductSlice\SliceRows;
use Faker\Generator;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;

/**
 * The fixed edge-case orders plus the bulk orders drawn from one seed. The orders slice reseeds before drawing,
 * so its rows never depend on what the products slice drew.
 */
final readonly class OrderSliceGenerator
{
    /**
     * @throws BindingResolutionException
     */
    public function generate(OrderProfile $profile, int $seed): SliceRows
    {
        // intentional: never Faker\Factory::create() — a discarded Generator's __destruct() reseeds the global
        // mt_rand state at random, breaking any seeded slice generated after it. The container keeps one per locale.
        $faker = Container::getInstance()->make(Generator::class, ['locale' => 'en_GB']);
        $faker->seed($seed);

        $bulk = new BulkOrderBuilder($faker)->build($profile);
        $rows = new OrderRowBuilder(new SeedRandom($faker));

        foreach (EdgeCaseOrders::all() as $edgeCase) {
            if ($edgeCase->superseded !== null) {
                $rows->add($edgeCase->superseded);
            }

            $rows->add($edgeCase->order);
        }

        foreach ($bulk as $order) {
            $rows->add($order);
        }

        return $rows->rows();
    }
}
