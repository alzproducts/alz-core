<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\Results;

use App\Application\QuickBooks\Mappings\DTOs\UnmatchedMappingDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;

final readonly class MappingAutoResolveResult
{
    /**
     * @param list<MappingKey>          $resolved  keys recorded by this run
     * @param list<UnmatchedMappingDTO> $unmatched
     */
    public function __construct(
        public bool $refused,
        public array $resolved = [],
        public array $unmatched = [],
    ) {}
}
