<?php

declare(strict_types=1);

namespace App\Application\Catalog\DTOs;

use App\Application\Catalog\Enums\CreditTier;
use App\Domain\ValueObjects\IntId;

final readonly class CreditTierLabelChangeDTO
{
    public function __construct(
        public IntId $productId,
        public ?CreditTier $targetTier,
    ) {}
}
