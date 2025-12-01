<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\ValueObjects\CategoryImage as DomainCategoryImage;
use App\Infrastructure\Contracts\DomainConvertible;
use Spatie\LaravelData\Data;

/**
 * ShopWired API Response: Category Image
 *
 * Nested object within Category response containing the image URL.
 * Named CategoryImage to avoid collision with Product images later.
 *
 * @see Category::$image
 */
final class CategoryImage extends Data implements DomainConvertible
{
    public function __construct(
        public readonly string $url,
    ) {}

    /**
     * Convert to Domain Value Object.
     */
    public function toDomain(): DomainCategoryImage
    {
        return new DomainCategoryImage(
            url: $this->url,
        );
    }
}
