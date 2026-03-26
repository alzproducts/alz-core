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
 * Graceful-degradation wrapper around CustomFieldValueFactory for the sync/read path.
 *
 * Delegates to CustomFieldValueFactory for typed value creation. Unknown fields
 * (CustomFieldNotFoundException) are logged as warnings and skipped — preserving
 * the read path's tolerance for out-of-sync field definitions.
 *
 * For strict validation (write path), use CustomFieldValueFactory directly.
 *
 * **Lifecycle**: Register with `scoped()` binding to ensure fresh instance per queue job.
 * This prevents stale custom field definitions in Octane long-running processes.
 */
final class ProductCustomFieldFactory
{
    private ?CustomFieldDefinitionRegistry $registry = null;

    public function __construct(
        private readonly CustomFieldRepositoryInterface $customFieldRepository,
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
                Log::warning('Unknown custom field in product - re-run SyncCustomFieldsJob', [
                    'field_name' => $name,
                    'item_type' => CustomFieldItemType::Product->value,
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
            $this->registry = CustomFieldDefinitionRegistry::forItemType($definitions, CustomFieldItemType::Product);
        }

        return $this->registry;
    }
}
