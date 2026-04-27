<?php

declare(strict_types=1);

namespace App\Application\ClickUp\Queries;

final readonly class ClickUpTaskQueryParams
{
    /**
     * @param list<string> $statuses Opaque status strings from frontend (not enumerated by alz-core)
     * @param list<string> $tags     Opaque tag strings from frontend
     */
    public function __construct(
        public array $statuses = [],
        public array $tags = [],
    ) {}
}
