<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Infrastructure\Shopwired\Contracts\PaginatableQueryParams;

/**
 * Immutable value object for ShopWired Order API query parameters.
 *
 * Wraps ShopwiredQueryParams for pagination and adds order-specific filters:
 * - from: Unix timestamp for start date filter
 * - to: Unix timestamp for end date filter
 * - status: Order status ID filter
 * - archived: Filter by archived status
 *
 * @internal For use within ShopWired infrastructure only
 */
final readonly class OrderQueryParams implements PaginatableQueryParams
{
    /**
     * @param ShopwiredQueryParams $baseParams Core pagination/sorting params
     * @param int|null $from Unix timestamp - filter orders created on or after
     * @param int|null $to Unix timestamp - filter orders created on or before
     * @param int|null $status Order status ID filter
     * @param bool|null $archived Filter by archived status (null = all)
     */
    public function __construct(
        private ShopwiredQueryParams $baseParams = new ShopwiredQueryParams(),
        public ?int $from = null,
        public ?int $to = null,
        public ?int $status = null,
        public ?bool $archived = null,
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
     *
     * @noinspection PhpUnnecessaryStaticReferenceInspection
     */
    public function nextPage(): static
    {
        return new self(
            baseParams: $this->baseParams->nextPage(),
            from: $this->from,
            to: $this->to,
            status: $this->status,
            archived: $this->archived,
        );
    }

    /**
     * Set items per page.
     */
    public function withCount(int $count): self
    {
        return new self(
            baseParams: $this->baseParams->withCount($count),
            from: $this->from,
            to: $this->to,
            status: $this->status,
            archived: $this->archived,
        );
    }

    /**
     * Set starting offset.
     */
    public function withOffset(int $offset): self
    {
        return new self(
            baseParams: $this->baseParams->withOffset($offset),
            from: $this->from,
            to: $this->to,
            status: $this->status,
            archived: $this->archived,
        );
    }

    /**
     * Filter by start date (Unix timestamp).
     */
    public function withFrom(?int $from): self
    {
        return new self(
            baseParams: $this->baseParams,
            from: $from,
            to: $this->to,
            status: $this->status,
            archived: $this->archived,
        );
    }

    /**
     * Filter by end date (Unix timestamp).
     */
    public function withTo(?int $to): self
    {
        return new self(
            baseParams: $this->baseParams,
            from: $this->from,
            to: $to,
            status: $this->status,
            archived: $this->archived,
        );
    }

    /**
     * Filter by order status ID.
     */
    public function withStatus(?int $status): self
    {
        return new self(
            baseParams: $this->baseParams,
            from: $this->from,
            to: $this->to,
            status: $status,
            archived: $this->archived,
        );
    }

    /**
     * Filter by archived status.
     *
     * @param bool|null $archived true=archived only, false=non-archived only, null=all
     */
    public function withArchived(?bool $archived): self
    {
        return new self(
            baseParams: $this->baseParams,
            from: $this->from,
            to: $this->to,
            status: $this->status,
            archived: $archived,
        );
    }

    /**
     * Replace the base query params (for embeds, fields, etc.).
     */
    public function withBaseParams(ShopwiredQueryParams $baseParams): self
    {
        return new self(
            baseParams: $baseParams,
            from: $this->from,
            to: $this->to,
            status: $this->status,
            archived: $this->archived,
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

        if ($this->from !== null) {
            $query['from'] = $this->from;
        }

        if ($this->to !== null) {
            $query['to'] = $this->to;
        }

        if ($this->status !== null) {
            $query['status'] = $this->status;
        }

        if ($this->archived !== null) {
            $query['archived'] = $this->archived ? '1' : '0';
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
