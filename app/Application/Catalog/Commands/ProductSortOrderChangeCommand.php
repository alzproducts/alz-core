<?php

declare(strict_types=1);

namespace App\Application\Catalog\Commands;

use App\Domain\ValueObjects\IntId;

final readonly class ProductSortOrderChangeCommand
{
    public function __construct(
        public IntId $productId,
        public int   $sortOrder,
    ) {}
}
