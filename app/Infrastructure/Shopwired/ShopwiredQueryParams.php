<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use InvalidArgumentException;

/**
 * Immutable value object for ShopWired API query parameters.
 *
 * Supports all common query params across endpoints:
 * - Pagination: count (1-100), offset (0+)
 * - Sorting: sort (endpoint-specific, pass enum->value)
 * - Embeds: embed=parents,children
 *
 * Fluent interface with immutable "with" methods.
 *
 * @internal For use within ShopWired infrastructure only
 */
final readonly class ShopwiredQueryParams
{
    public const int MAX_COUNT = 100;

    public const int DEFAULT_COUNT = 50;

    /**
     * @param int $count Items per page (1-100)
     * @param int $offset Starting position
     * @param list<string> $embeds Related resources to embed
     * @param string|null $sort Sort order (from endpoint-specific enum)
     */
    public function __construct(
        public int $count = self::DEFAULT_COUNT,
        public int $offset = 0,
        public array $embeds = [],
        public ?string $sort = null,
    ) {
        if (($count < 1) || ($count > self::MAX_COUNT)) {
            throw new InvalidArgumentException(
                \sprintf('Count must be between 1 and %d, got %d', self::MAX_COUNT, $count),
            );
        }

        if ($offset < 0) {
            throw new InvalidArgumentException(
                \sprintf('Offset must be non-negative, got %d', $offset),
            );
        }
    }

    /**
     * Create with maximum page size for bulk fetching.
     */
    public static function forBulkFetch(): self
    {
        return new self(count: self::MAX_COUNT);
    }

    public function withCount(int $count): self
    {
        return new self($count, $this->offset, $this->embeds, $this->sort);
    }

    public function withOffset(int $offset): self
    {
        return new self($this->count, $offset, $this->embeds, $this->sort);
    }

    /**
     * @param list<string> $embeds
     */
    public function withEmbeds(array $embeds): self
    {
        /** @var list<string> $embeds */
        return new self($this->count, $this->offset, $embeds, $this->sort);
    }

    /**
     * Set sort order from endpoint-specific enum.
     *
     * @param string|null $sort The enum value (e.g., CategorySort::CreatedDesc->value)
     */
    public function withSort(?string $sort): self
    {
        return new self($this->count, $this->offset, $this->embeds, $sort);
    }

    /**
     * Advance offset to next page.
     */
    public function nextPage(): self
    {
        return new self($this->count, $this->offset + $this->count, $this->embeds, $this->sort);
    }

    /**
     * Convert to HTTP query array for transport.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        $query = [
            'count' => $this->count,
            'offset' => $this->offset,
        ];

        if ($this->sort !== null) {
            $query['sort'] = $this->sort;
        }

        if ($this->embeds !== []) {
            $query['embed'] = \implode(',', $this->embeds);
        }

        return $query;
    }

    /**
     * Check if this represents the first page.
     */
    public function isFirstPage(): bool
    {
        return $this->offset === 0;
    }
}
