<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\DTOs;

use Webmozart\Assert\Assert;

/**
 * An unlabelled custom-field slot has an empty name.
 */
final readonly class MappingCandidateDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $active,
        public ?string $detail = null,
    ) {
        Assert::stringNotEmpty($id, 'A mapping candidate needs a QBO id');
    }
}
