<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Infrastructure\Shopwired\Contracts\PaginatableQueryParams;

/**
 * Immutable value object for ShopWired Customer API query parameters.
 *
 * Wraps ShopwiredQueryParams for pagination and adds customer-specific filters:
 * - trade: Filter by trade status (true=trade only, false=non-trade only, null=all)
 * - email: Filter by exact email match
 *
 * @internal For use within ShopWired infrastructure only
 */
final readonly class CustomerQueryParams implements PaginatableQueryParams
{
    /**
     * @param ShopwiredQueryParams $baseParams Core pagination/sorting params
     * @param bool|null $trade Filter by trade status (null = all customers)
     * @param string|null $email Filter by exact email address
     */
    public function __construct(
        private ShopwiredQueryParams $baseParams = new ShopwiredQueryParams(),
        public ?bool $trade = null,
        public ?string $email = null,
    ) {}

    /**
     * Create with maximum page size for bulk fetching.
     */
    public static function forBulkFetch(): self
    {
        return new self(baseParams: ShopwiredQueryParams::forBulkFetch());
    }

    /**
     * Get the page size (items per page).
     */
    public function getCount(): int
    {
        return $this->baseParams->getCount();
    }

    /**
     * Create new params advanced to the next page.
     */
    public function nextPage(): static
    {
        return new self(
            baseParams: $this->baseParams->nextPage(),
            trade: $this->trade,
            email: $this->email,
        );
    }

    /**
     * Set items per page.
     */
    public function withCount(int $count): self
    {
        return new self(
            baseParams: $this->baseParams->withCount($count),
            trade: $this->trade,
            email: $this->email,
        );
    }

    /**
     * Set starting offset.
     */
    public function withOffset(int $offset): self
    {
        return new self(
            baseParams: $this->baseParams->withOffset($offset),
            trade: $this->trade,
            email: $this->email,
        );
    }

    /**
     * Filter by trade status.
     *
     * @param bool|null $trade true=trade only, false=non-trade only, null=all
     */
    public function withTrade(?bool $trade): self
    {
        return new self(
            baseParams: $this->baseParams,
            trade: $trade,
            email: $this->email,
        );
    }

    /**
     * Filter by exact email address.
     */
    public function withEmail(?string $email): self
    {
        return new self(
            baseParams: $this->baseParams,
            trade: $this->trade,
            email: $email,
        );
    }

    /**
     * Convert to HTTP query array for transport.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        $query = $this->baseParams->toArray();

        if ($this->trade !== null) {
            $query['trade'] = $this->trade ? '1' : '0';
        }

        if ($this->email !== null) {
            $query['email'] = $this->email;
        }

        return $query;
    }

    /**
     * Check if this represents the first page.
     */
    public function isFirstPage(): bool
    {
        return $this->baseParams->isFirstPage();
    }
}
