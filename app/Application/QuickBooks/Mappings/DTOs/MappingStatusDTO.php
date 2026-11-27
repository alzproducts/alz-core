<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\MappingState;

final readonly class MappingStatusDTO
{
    public function __construct(
        public MappingDTO $mapping,
        public MappingState $state,
    ) {}
}
