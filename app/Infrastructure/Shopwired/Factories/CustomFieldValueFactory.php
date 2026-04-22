<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Factories;

use App\Application\Contracts\Shopwired\CustomFieldRepositoryInterface;
use App\Application\Contracts\Shopwired\CustomFieldValueFactoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Exceptions\CustomFieldNotFoundException;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\DateTimeCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ProductListCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ToggleCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ValueListCustomFieldValue;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Shopwired\CustomFields\CustomFieldDefinitionRegistry;

/**
 * Strict factory for creating typed custom field values.
 *
 * Throws on ALL errors (unknown fields, type mismatches).
 * Used by the write path for validating user-submitted custom field data.
 *
 * @see CustomFieldFactory For the graceful-degradation version (sync/read path)
 *
 * **Lifecycle**: Register with `scoped()` binding to ensure fresh instance per queue job.
 */
final class CustomFieldValueFactory implements CustomFieldValueFactoryInterface
{
    private ?CustomFieldDefinitionRegistry $registry = null;

    public function __construct(
        private readonly CustomFieldRepositoryInterface $customFieldRepository,
        private readonly CustomFieldItemType $itemType,
    ) {}

    /**
     * @param array<string, mixed> $rawFields Field name => value pairs
     *
     * @return list<AbstractCustomFieldValue>
     *
     * @throws CustomFieldNotFoundException When a field name is not in the registry
     * @throws InvalidCustomFieldValueException When a value type mismatches the definition
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function fromRawFields(array $rawFields): array
    {
        $result = [];

        foreach ($rawFields as $name => $value) {
            $definition = $this->registry()->findByName($name);

            if ($definition === null) {
                throw new CustomFieldNotFoundException(
                    fieldName: $name,
                    itemType: $this->itemType,
                );
            }

            // Null means "clear this field" — skip type validation.
            // The merge logic in ProductUpdateClient handles null by removing the field.
            if ($value === null) {
                continue;
            }

            // Write-path validation: reject invalid choice values before VO construction
            if ($definition->base->hasAllowedValues() && \is_string($value) && !$definition->base->isValueAllowed($value)) {
                throw new InvalidCustomFieldValueException(
                    fieldName: $name,
                    expectedType: $definition->base->type,
                    actualType: 'string (invalid choice)',
                    rawValue: $value,
                );
            }

            $result[] = self::createTypedValueFromDefinition($definition, $value);
        }

        return $result;
    }

    /**
     * Create a typed CustomFieldValue from a configured definition and raw value.
     *
     * Public so CustomFieldFactory can delegate individual field creation
     * while handling unknown-field logic itself.
     *
     * @throws InvalidCustomFieldValueException When value type mismatches definition
     */
    public static function createTypedValueFromDefinition(ConfiguredFieldDefinition $definition, mixed $value): AbstractCustomFieldValue
    {
        return match ($definition->base->type) {
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
    private static function createStringValue(ConfiguredFieldDefinition $definition, mixed $value): StringCustomFieldValue
    {
        if (!\is_string($value)) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->base->name,
                expectedType: $definition->base->type,
                actualType: \get_debug_type($value),
                rawValue: $value,
            );
        }

        return new StringCustomFieldValue($definition, $value);
    }

    /**
     * @throws InvalidCustomFieldValueException
     */
    private static function createToggleValue(ConfiguredFieldDefinition $definition, mixed $value): ToggleCustomFieldValue
    {
        if (!\is_bool($value)) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->base->name,
                expectedType: $definition->base->type,
                actualType: \get_debug_type($value),
                rawValue: $value,
            );
        }

        return new ToggleCustomFieldValue($definition, $value);
    }

    /**
     * @throws InvalidCustomFieldValueException
     */
    private static function createDateTimeValue(ConfiguredFieldDefinition $definition, mixed $value): DateTimeCustomFieldValue
    {
        if (!\is_int($value)) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->base->name,
                expectedType: $definition->base->type,
                actualType: \get_debug_type($value),
                rawValue: $value,
            );
        }

        return DateTimeCustomFieldValue::fromTimestamp($definition, $value);
    }

    /**
     * @throws InvalidCustomFieldValueException
     */
    private static function createValueListValue(ConfiguredFieldDefinition $definition, mixed $value): ValueListCustomFieldValue
    {
        if (!\is_array($value)) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->base->name,
                expectedType: $definition->base->type,
                actualType: \get_debug_type($value),
                rawValue: $value,
            );
        }

        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw new InvalidCustomFieldValueException(
                    fieldName: $definition->base->name,
                    expectedType: $definition->base->type,
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
    private static function createProductListValue(ConfiguredFieldDefinition $definition, mixed $value): ProductListCustomFieldValue
    {
        if (!\is_array($value)) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->base->name,
                expectedType: $definition->base->type,
                actualType: \get_debug_type($value),
                rawValue: $value,
            );
        }

        foreach ($value as $item) {
            if (!\is_int($item) || $item <= 0) {
                throw new InvalidCustomFieldValueException(
                    fieldName: $definition->base->name,
                    expectedType: $definition->base->type,
                    actualType: 'array with invalid product ID: ' . \get_debug_type($item),
                    rawValue: $value,
                );
            }
        }

        /** @var list<int> $value */
        return new ProductListCustomFieldValue($definition, $value);
    }

    /**
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
