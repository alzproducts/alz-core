<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\MappingKey;

final readonly class UnmatchedMappingDTO
{
    public function __construct(
        public MappingKey $key,
        public string $reason,
    ) {}
}
