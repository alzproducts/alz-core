<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Factories;

use App\Application\Contracts\Shopwired\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\DateTimeCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ProductListCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ToggleCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ValueListCustomFieldValue;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Shopwired\CustomFields\CustomFieldDefinitionRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Factory for typing raw custom field values into domain objects.
 *
 * Joins raw custom field data (name → value map) with the CustomFieldDefinitionRegistry
 * to produce typed AbstractCustomFieldValue instances.
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
                // Field not in registry - likely a new field added in ShopWired
                Log::warning('Unknown custom field in product - re-run SyncCustomFieldsJob', [
                    'field_name' => $name,
                    'item_type' => CustomFieldItemType::Product->value,
                ]);

                continue;
            }

            $result[] = self::createTypedValue($definition, $value);
        }

        return $result;
    }

    /**
     * Create a typed CustomFieldValue from a definition and raw value.
     *
     * @throws InvalidCustomFieldValueException When value type mismatches definition
     */
    private static function createTypedValue(CustomFieldDefinition $definition, mixed $value): AbstractCustomFieldValue
    {
        return match ($definition->type) {
            CustomFieldType::Text,
            CustomFieldType::Choice,
            CustomFieldType::List => self::createStringValue($definition, $value),

            CustomFieldType::Toggle => self::createToggleValue($definition, $value),

            CustomFieldType::Date,
            CustomFieldType::DateTime => self::createDateTimeValue($definition, $value),

            CustomFieldType::ValueList => self::createValueListValue($definition, $value),

            CustomFieldType::ProductList => self::createProductListValue($definition, $value),
        };
    }

    /**
     * @throws InvalidCustomFieldValueException
     */
    private static function createStringValue(CustomFieldDefinition $definition, mixed $value): StringCustomFieldValue
    {
        if (!\is_string($value)) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->name,
                expectedType: $definition->type,
                actualType: \get_debug_type($value),
                rawValue: $value,
            );
        }

        return new StringCustomFieldValue($definition, $value);
    }

    /**
     * @throws InvalidCustomFieldValueException
     */
    private static function createToggleValue(CustomFieldDefinition $definition, mixed $value): ToggleCustomFieldValue
    {
        if (!\is_bool($value)) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->name,
                expectedType: $definition->type,
                actualType: \get_debug_type($value),
                rawValue: $value,
            );
        }

        return new ToggleCustomFieldValue($definition, $value);
    }

    /**
     * @throws InvalidCustomFieldValueException
     */
    private static function createDateTimeValue(CustomFieldDefinition $definition, mixed $value): DateTimeCustomFieldValue
    {
        if (!\is_int($value)) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->name,
                expectedType: $definition->type,
                actualType: \get_debug_type($value),
                rawValue: $value,
            );
        }

        return DateTimeCustomFieldValue::fromTimestamp($definition, $value);
    }

    /**
     * @throws InvalidCustomFieldValueException
     */
    private static function createValueListValue(CustomFieldDefinition $definition, mixed $value): ValueListCustomFieldValue
    {
        if (!\is_array($value)) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->name,
                expectedType: $definition->type,
                actualType: \get_debug_type($value),
                rawValue: $value,
            );
        }

        // Validate all items are strings
        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw new InvalidCustomFieldValueException(
                    fieldName: $definition->name,
                    expectedType: $definition->type,
                    actualType: 'array with non-string element: ' . \get_debug_type($item),
                    rawValue: $value,
                );
            }
        }

        /** @var list<string> $value */
        return new ValueListCustomFieldValue($definition, $value);
    }

    /**
     * @throws InvalidCustomFieldValueException
     */
    private static function createProductListValue(CustomFieldDefinition $definition, mixed $value): ProductListCustomFieldValue
    {
        if (!\is_array($value)) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->name,
                expectedType: $definition->type,
                actualType: \get_debug_type($value),
                rawValue: $value,
            );
        }

        // Validate all items are positive integers
        foreach ($value as $item) {
            if (!\is_int($item) || $item <= 0) {
                throw new InvalidCustomFieldValueException(
                    fieldName: $definition->name,
                    expectedType: $definition->type,
                    actualType: 'array with invalid product ID: ' . \get_debug_type($item),
                    rawValue: $value,
                );
            }
        }

        /** @var list<int> $value */
        return new ProductListCustomFieldValue($definition, $value);
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
