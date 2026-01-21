<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Product Image Value Object.
 *
 * Represents a single image associated with a product.
 *
 * @see https://shopwired.readme.io/reference/getproduct
 */
final readonly class ProductImage
{
    /**
     * @param int $id ShopWired image ID
     * @param string $url Full URL to the image
     * @param string|null $description Alt text / description
     * @param int $sortOrder Display order (0-indexed)
     */
    public function __construct(
        public int $id,
        public string $url,
        public ?string $description,
        public int $sortOrder,
    ) {
        Assert::greaterThan($id, 0, 'Image ID must be positive');
        Assert::notEmpty($url, 'Image URL cannot be empty');
        Assert::greaterThanEq($sortOrder, 0, 'Sort order cannot be negative');
    }

    /**
     * Check if this is the primary (first) image.
     */
    public function isPrimary(): bool
    {
        return $this->sortOrder === 0;
    }

    /**
     * Convert to array for JSONB storage.
     *
     * @return array{id: int, url: string, description: string|null, sort_order: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'description' => $this->description,
            'sort_order' => $this->sortOrder,
        ];
    }

    /**
     * Create from array (JSONB hydration).
     *
     * @param array{id: int, url: string, description: string|null, sort_order: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            url: $data['url'],
            description: $data['description'],
            sortOrder: $data['sort_order'],
        );
    }
}
