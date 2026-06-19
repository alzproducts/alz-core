<?php

declare(strict_types=1);

namespace App\Application\Catalog\DTOs;

use App\Application\Catalog\Enums\MarginTier;
use App\Domain\ValueObjects\IntId;

final readonly class MarginTierAssignmentDTO
{
    public function __construct(
        public IntId $productId,
        public MarginTier $targetLabel,
    ) {}
}
