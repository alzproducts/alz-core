<?php

declare(strict_types=1);

namespace App\Application\Catalog\Results;

use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\ValueObjects\Uuid;

/**
 * Compound lookup result pairing a custom field definition with its internal catalog UUID.
 *
 * {@see ConfiguredFieldDefinition} intentionally does not expose the internal UUID (Phase 1
 * read model stays FK-free). Write use cases that need both the enriched read model AND the
 * UUID for settings-row FKs receive this tuple from
 * {@see CustomFieldRepositoryInterface::findEnrichedWithInternalId()} in a single repository
 * round-trip.
 */
final readonly class CustomFieldResolutionResult
{
    public function __construct(
        public Uuid $internalId,
        public ConfiguredFieldDefinition $definition,
    ) {}
}
