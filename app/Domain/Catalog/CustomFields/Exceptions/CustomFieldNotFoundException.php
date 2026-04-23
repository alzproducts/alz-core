<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\Exceptions;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Exceptions\DomainException;
use Override;
use Throwable;

/**
 * Custom field definition not found in registry.
 *
 * Thrown when attempting to hydrate a custom field value but the field name
 * is not present in the CustomFieldDefinitionRegistry. This indicates:
 * - Custom field definitions are out of sync (need to re-run sync job)
 * - API returned a field that was recently added in ShopWired
 * - Data corruption or unexpected API response
 *
 * This is a runtime error that should surface immediately during product sync
 * so it can be investigated. The Application layer decides how to handle it
 * (fail entire sync vs. log and skip individual products).
 */
final class CustomFieldNotFoundException extends DomainException
{
    public function __construct(
        public readonly string $fieldName,
        public readonly CustomFieldItemType $itemType,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Custom field not found in registry', previous: $previous);
    }

    #[Override]
    public function context(): array
    {
        return [
            'field_name' => $this->fieldName,
            'item_type' => $this->itemType->value,
        ];
    }
}
