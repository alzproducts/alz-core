<?php

declare(strict_types=1);

namespace App\Application\Catalog\DTOs;

use App\Domain\ValueObjects\IntId;

final readonly class ProductSortOrderChangeDTO
{
    public function __construct(
        public IntId $productId,
        public int   $sortOrder,
    ) {}
}
