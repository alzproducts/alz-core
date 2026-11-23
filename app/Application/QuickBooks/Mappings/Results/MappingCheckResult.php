<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\Results;

use App\Application\QuickBooks\Mappings\DTOs\MappingFindingDTO;

final readonly class MappingCheckResult
{
    /**
     * @param list<MappingFindingDTO> $findings
     */
    public function __construct(public array $findings) {}
}
