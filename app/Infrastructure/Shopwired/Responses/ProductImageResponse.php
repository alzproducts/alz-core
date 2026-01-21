<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Product Image.
 *
 * Nested object within Product response containing image data.
 *
 * @see https://shopwired.readme.io/reference/getproduct
 */
#[MapInputName(SnakeCaseMapper::class)]
final class ProductImageResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly ?string $description,
        public readonly int $sortOrder,
    ) {}

    public function toDomain(): ProductImage
    {
        return new ProductImage(
            id: $this->id,
            url: $this->url,
            description: $this->description,
            sortOrder: $this->sortOrder,
        );
    }
}
