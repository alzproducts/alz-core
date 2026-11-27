<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use DateTimeImmutable;

final readonly class MappingDTO
{
    public function __construct(
        public MappingKey $key,
        public ?string $refId,
        public ?string $refName,
        public ?string $realmId,
        public ?DateTimeImmutable $resolvedAt = null,
    ) {}
}
