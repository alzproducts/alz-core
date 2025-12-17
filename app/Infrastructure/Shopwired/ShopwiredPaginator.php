<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Infrastructure\Shopwired\Contracts\PaginatableQueryParams;
use Closure;

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
     * @template P of PaginatableQueryParams
     *
     * @param P $params Initial query parameters (offset should be 0)
     * @param Closure(P): list<T> $fetchPage Callback to fetch one page
     * @param-immediately-invoked-callable $fetchPage
     * @param int|null $knownTotal Optional total count (stops at this count if provided)
     *
     * @return list<T> All items across all pages
     */
    public static function fetchAll(
        PaginatableQueryParams $params,
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
