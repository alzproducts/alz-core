<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Customer Wishlist (embedded).
 *
 * Infrastructure DTO for parsing wishlist data from customer responses.
 * This is NOT converted to a Domain VO as wishlists are ShopWired-specific
 * and have no current business logic use case.
 *
 * @see https://shopwired.readme.io/reference/listcustomers
 */
#[MapInputName(SnakeCaseMapper::class)]
final class CustomerWishlist extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $token,
        public readonly bool $isPublic,
    ) {}
}
