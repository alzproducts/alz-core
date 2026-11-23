<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use Webmozart\Assert\Assert;

final readonly class MappingPickDTO
{
    public function __construct(
        public MappingKey $key,
        public string $refId,
        public string $refName,
        public string $realmId,
    ) {
        Assert::stringNotEmpty($refId, 'A mapping pick needs a QBO id');
        Assert::stringNotEmpty($realmId, 'A mapping pick needs a realm');
    }
}
