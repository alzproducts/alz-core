<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\MappingFindingKind;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;

final readonly class MappingFindingDTO
{
    /**
     * @param string|null $currentName null when QBO did not return the entity
     */
    public function __construct(
        public MappingKey $key,
        public MappingFindingKind $kind,
        public ?string $storedName = null,
        public ?string $currentName = null,
    ) {}
}
