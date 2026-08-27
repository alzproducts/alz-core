<?php

declare(strict_types=1);

namespace App\Application\Catalog\BestSellerLabels;

use App\Domain\ValueObjects\IntId;

final readonly class BestSellerLabelAssignmentDTO
{
    public function __construct(
        public IntId $productId,
        public ?string $label,
    ) {}
}
