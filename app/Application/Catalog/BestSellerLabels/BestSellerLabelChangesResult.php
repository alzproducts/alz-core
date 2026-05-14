<?php

declare(strict_types=1);

namespace App\Application\Catalog\BestSellerLabels;

final readonly class BestSellerLabelChangesResult
{
    /**
     * @param list<ProductLabelCandidateDTO> $toAdd
     * @param list<ProductLabelCandidateDTO> $toRemove
     */
    public function __construct(
        public array $toAdd,
        public array $toRemove,
    ) {}

    public function hasChanges(): bool
    {
        return $this->toAdd !== [] || $this->toRemove !== [];
    }
}
