<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

/**
 * Fixed request shapes and samples the snapshot and the bench share, chosen to return rows on the COR-252 seed.
 */
final class ProductListRequestShapes
{
    /** @var array<string, string> */
    public const array PRODUCT_LIST = [
        'products-dashboard' => '/api/products?per_page=1000&page=1&include=stock',
        'products-default-page' => '/api/products',
        'products-page-2' => '/api/products?per_page=500&page=2',
        'products-filtered' => '/api/products?category_id=2000000142',
        'products-sorted' => '/api/products?sort_by=price&sort_direction=desc',
        'products-searched' => '/api/products?search=voluptatem',
    ];

    public const string VARIATIONS_SHAPE = 'variations-default-page';

    public const string VARIATIONS_URI = '/api/products/variations';

    /**
     * One slot per 5% of traffic: 70% dashboard, 10% default page, 5% each of page 2, filtered, sorted and searched.
     *
     * @var list<string>
     */
    public const array BENCH_MIX = [
        'products-dashboard', 'products-default-page', 'products-dashboard', 'products-page-2',
        'products-dashboard', 'products-filtered', 'products-dashboard', 'products-dashboard',
        'products-sorted', 'products-dashboard', 'products-default-page', 'products-dashboard',
        'products-searched', 'products-dashboard', 'products-dashboard', 'products-dashboard',
        'products-dashboard', 'products-dashboard', 'products-dashboard', 'products-dashboard',
    ];

    /**
     * Covers each active / on-sale / free-delivery / variation-count combination on the seed; the last has 120 variations.
     *
     * @var list<int>
     */
    public const array DETAIL_PRODUCT_IDS = [
        2_010_000_000, 2_010_000_001, 2_010_000_009, 2_010_000_087, 2_010_000_128,
        2_010_000_153, 2_010_000_240, 2_010_000_595, 2_010_000_569,
    ];

    public const int FILTERED_CATEGORY_ID = 2_000_000_142;

    public static function benchUri(int $requestIndex): string
    {
        $shape = self::BENCH_MIX[\abs($requestIndex) % \count(self::BENCH_MIX)];

        return self::PRODUCT_LIST[$shape];
    }
}
