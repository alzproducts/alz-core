<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\Exceptions;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Exceptions\DomainException;
use Throwable;

/**
 * Custom field value does not match expected type from definition.
 *
 * Thrown when a custom field value cannot be hydrated because the raw value
 * from the API doesn't match the type specified in the field definition:
 * - Toggle field received a string instead of boolean
 * - ProductList field received a scalar instead of array
 * - ValueList field received integers instead of strings
 *
 * This indicates data inconsistency between ShopWired's custom field
 * definitions and the actual values stored on products. Should surface
 * immediately for investigation.
 */
final class InvalidCustomFieldValueException extends DomainException
{
    public readonly string $rawValueType;

    public function __construct(
        public readonly string $fieldName,
        public readonly CustomFieldType $expectedType,
        public readonly string $actualType,
        public readonly mixed $rawValue,
        ?Throwable $previous = null,
    ) {
        $this->rawValueType = \get_debug_type($rawValue);
        parent::__construct('Custom field value type mismatch', previous: $previous);
    }

    public function context(): array
    {
        return [
            'field_name' => $this->fieldName,
            'expected_type' => $this->expectedType->value,
            'actual_type' => $this->actualType,
            'raw_value_type' => $this->rawValueType,
        ];
    }
}
