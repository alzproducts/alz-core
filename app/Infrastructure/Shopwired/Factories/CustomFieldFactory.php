<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Factories;

use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Shopwired\CustomFields\CustomFieldDefinitionRegistry;
use App\Infrastructure\Shopwired\CustomFields\UnknownCustomFieldReporter;

/**
 * Graceful-degradation factory for creating typed custom field values on the sync/read path.
 *
 * Delegates to CustomFieldValueFactory for typed value creation. Unknown fields
 * are skipped and recorded with the injected UnknownCustomFieldReporter, which
 * aggregates across every factory in the request and emits one summary log line
 * — preserving the read path's tolerance for out-of-sync field definitions
 * without flooding the log when many products share the same unknown field.
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
        private readonly UnknownCustomFieldReporter $reporter,
    ) {}

    /**
     * Build typed custom field values from raw data.
     *
     * Unknown field names are counted and emitted as a single per-request
     * summary warning (may indicate custom field definitions are out of sync -
     * re-run SyncShopwiredCustomFieldsJob).
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
     * @throws MissingRequiredDataException When custom field definitions table is empty
     */
    public function fromRawFields(array $rawFields): array
    {
        $result = [];

        foreach ($rawFields as $name => $value) {
            $typed = $this->resolveTypedValue($name, $value);

            if ($typed !== null) {
                $result[] = $typed;
            }
        }

        return $result;
    }

    /**
     * Resolve a single raw (name, value) pair to a typed value, or null when
     * the entry should be skipped (unknown field — counted for summary warning;
     * null value — caller's "clear this field" intent, merged downstream by
     * GetProductCustomFieldsUseCase::mergeWithDefinitions()).
     *
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When value type mismatches definition
     * @throws MissingRequiredDataException When custom field definitions table is empty
     */
    private function resolveTypedValue(string $name, mixed $value): ?AbstractCustomFieldValue
    {
        $definition = $this->registry()->findByName($name);

        if ($definition === null) {
            $this->reporter->record($this->itemType, $name);

            return null;
        }

        if ($value === null) {
            return null;
        }

        return CustomFieldValueFactory::createTypedValueFromDefinition($definition, $value);
    }

    /**
     * Get the custom field definition registry, lazy-loading on first access.
     *
     * @throws DatabaseOperationFailedException When query fails
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws MissingRequiredDataException When custom field definitions table is empty
     */
    private function registry(): CustomFieldDefinitionRegistry
    {
        if ($this->registry === null) {
            $definitions = $this->customFieldRepository->findAll();

            if ($definitions === []) {
                throw new MissingRequiredDataException(
                    dataType: 'custom field definitions',
                    operation: "CustomFieldFactory ({$this->itemType->value})",
                    resolution: 'Run SyncShopwiredCustomFieldsJob or php artisan dev:seed-sync',
                );
            }

            $this->registry = CustomFieldDefinitionRegistry::forItemType($definitions, $this->itemType);
        }

        return $this->registry;
    }
}
