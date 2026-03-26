<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Factories;

use App\Application\Contracts\Shopwired\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Shopwired\CustomFields\CustomFieldDefinitionRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Graceful-degradation factory for creating typed custom field values on the sync/read path.
 *
 * Delegates to CustomFieldValueFactory for typed value creation. Unknown fields
 * (CustomFieldNotFoundException) are logged as warnings and skipped — preserving
 * the read path's tolerance for out-of-sync field definitions.
 *
 * Parameterised by CustomFieldItemType so the same factory serves Products,
 * Categories, and Brands — each getting a registry filtered to their item type.
 *
 * For strict validation (write path), use CustomFieldValueFactory directly.
 *
 * **Lifecycle**: Lazy-loads and caches the custom field registry on first use.
 * The consumer's binding lifecycle determines staleness — use `scoped()` binding
 * (or inject into a scoped consumer) to ensure fresh definitions per queue job.
 */
final class CustomFieldFactory
{
    private ?CustomFieldDefinitionRegistry $registry = null;

    public function __construct(
        private readonly CustomFieldRepositoryInterface $customFieldRepository,
        private readonly CustomFieldItemType $itemType,
    ) {}

    /**
     * Build typed custom field values from raw data.
     *
     * Unknown field names are logged as warnings and skipped (may indicate
     * custom field definitions are out of sync - re-run SyncCustomFieldsJob).
     * Type mismatches throw InvalidCustomFieldValueException.
     *
     * @param array<string, mixed> $rawFields Raw custom field data (name => value)
     *
     * @return list<AbstractCustomFieldValue>
     *
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When value type mismatches definition
     */
    public function fromRawFields(array $rawFields): array
    {
        $result = [];

        foreach ($rawFields as $name => $value) {
            $definition = $this->registry()->findByName($name);

            if ($definition === null) {
                Log::warning('Unknown custom field - re-run SyncCustomFieldsJob', [
                    'field_name' => $name,
                    'item_type' => $this->itemType->value,
                ]);

                continue;
            }

            $result[] = CustomFieldValueFactory::createTypedValueFromDefinition($definition, $value);
        }

        return $result;
    }

    /**
     * Get the custom field definition registry, lazy-loading on first access.
     *
     * @throws DatabaseOperationFailedException When query fails
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    private function registry(): CustomFieldDefinitionRegistry
    {
        if ($this->registry === null) {
            $definitions = $this->customFieldRepository->findAll();
            $this->registry = CustomFieldDefinitionRegistry::forItemType($definitions, $this->itemType);
        }

        return $this->registry;
    }
}
