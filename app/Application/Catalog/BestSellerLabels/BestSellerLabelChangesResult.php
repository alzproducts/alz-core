<?php

declare(strict_types=1);

namespace App\Application\Catalog\BestSellerLabels;

use App\Domain\ValueObjects\IntId;

final readonly class BestSellerLabelChangesResult
{
    /**
     * @param list<IntId> $toAdd
     * @param list<IntId> $toRemove
     */
    public function __construct(
        public array $toAdd,
        public array $toRemove,
    ) {}
}
