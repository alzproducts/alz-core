<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\DTOs;

final readonly class MappingOptionDTO
{
    /**
     * @param bool $current whether the key's row holds this candidate for the configured realm
     */
    public function __construct(
        public MappingCandidateDTO $candidate,
        public bool $current,
    ) {}
}
