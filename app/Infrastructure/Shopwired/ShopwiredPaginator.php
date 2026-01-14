<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Infrastructure\Shopwired\Contracts\PaginatableQueryParamsInterface;
use Closure;
use Generator;

/**
 * Paginator for fetching all pages from ShopWired API endpoints.
 *
 * Generic paginator that works with any endpoint. The caller provides
 * a fetch callback and receives all items across all pages.
 *
 * Uses count-based stopping (fetches until page returns fewer items
 * than requested, indicating final page).
 *
 * @internal For use within ShopWired infrastructure only
 */
final readonly class ShopwiredPaginator
{
    /**
     * Fetch all pages from an endpoint.
     *
     * @template T
     * @template P of PaginatableQueryParamsInterface
     *
     * @param P $params Initial query parameters (offset should be 0)
     * @param Closure(P): list<T> $fetchPage Callback to fetch one page
     * @param-immediately-invoked-callable $fetchPage
     * @param int|null $knownTotal Optional total count (stops at this count if provided)
     *
     * @return list<T> All items across all pages
     */
    public static function fetchAll(
        PaginatableQueryParamsInterface $params,
        Closure $fetchPage,
        ?int $knownTotal = null,
    ): array {
        $allItems = [];
        $currentParams = $params;

        do {
            /** @var list<T> $pageItems */
            $pageItems = $fetchPage($currentParams);
            $allItems = [...$allItems, ...$pageItems];

            // Stop if we got fewer items than requested (final page)
            if (\count($pageItems) < $currentParams->getCount()) {
                break;
            }

            // Stop if we've reached known total
            if (($knownTotal !== null) && (\count($allItems) >= $knownTotal)) {
                break;
            }

            $currentParams = $currentParams->nextPage();
        } while (true);

        return $allItems;
    }

    /**
     * Iterate pages from an endpoint (memory-efficient).
     *
     * Unlike fetchAll() which accumulates all items in memory, this generator
     * yields each page's items separately, allowing the caller to process
     * and discard each batch before fetching the next.
     *
     * Ideal for large datasets (e.g., 60k customers) where loading everything
     * into memory is not feasible.
     *
     * @template T
     * @template P of PaginatableQueryParamsInterface
     *
     * @param P $params Initial query parameters (offset should be 0)
     * @param Closure(P): list<T> $fetchPage Callback to fetch one page
     * @param-immediately-invoked-callable $fetchPage
     * @param int|null $knownTotal Optional total count (stops at this count if provided)
     *
     * @return Generator<int, list<T>, mixed, void> Yields each page's items (page number as key)
     */
    public static function pages(
        PaginatableQueryParamsInterface $params,
        Closure $fetchPage,
        ?int $knownTotal = null,
    ): Generator {
        $currentParams = $params;
        $totalFetched = 0;
        $pageNumber = 0;

        do {
            /** @var list<T> $pageItems */
            $pageItems = $fetchPage($currentParams);
            $totalFetched += \count($pageItems);

            yield $pageNumber => $pageItems;

            // Stop if we got fewer items than requested (final page)
            if (\count($pageItems) < $currentParams->getCount()) {
                break;
            }

            // Stop if we've reached known total
            if (($knownTotal !== null) && ($totalFetched >= $knownTotal)) {
                break;
            }

            $currentParams = $currentParams->nextPage();
            $pageNumber++;
        } while (true);
    }

    /**
     * Calculate total pages needed for a given count.
     */
    public static function calculatePageCount(int $totalItems, int $pageSize): int
    {
        if (($totalItems <= 0) || ($pageSize <= 0)) {
            return 0;
        }

        return (int) \ceil($totalItems / $pageSize);
    }
}
