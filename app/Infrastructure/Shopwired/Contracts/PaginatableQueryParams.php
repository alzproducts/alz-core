<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Contracts;

/**
 * Interface for query parameter objects that support pagination.
 *
 * Allows the ShopwiredPaginator to work with any query params object
 * (e.g., ShopwiredQueryParams, CustomerQueryParams) without tight coupling.
 *
 * Implementations must provide:
 * - Page size via getCount()
 * - Ability to advance to next page via nextPage()
 * - HTTP query array conversion via toArray()
 *
 * @internal For use within ShopWired infrastructure only
 */
interface PaginatableQueryParams
{
    /**
     * Get the page size (items per page).
     */
    public function getCount(): int;

    /**
     * Create new params advanced to the next page.
     *
     * @return static New instance with incremented offset
     */
    public function nextPage(): static;

    /**
     * Convert to HTTP query array for transport.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array;
}
