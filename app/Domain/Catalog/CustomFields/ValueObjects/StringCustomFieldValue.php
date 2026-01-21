<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use Webmozart\Assert\Assert;

/**
 * Custom field value containing a string.
 *
 * Used for field types:
 * - Text: Free-form text input
 * - Choice: Radio button selection (single value from allowedValues)
 * - List: Dropdown selection (single value from allowedValues)
 *
 * For Choice/List types, the value is validated against allowedValues at construction.
 */
final readonly class StringCustomFieldValue extends AbstractCustomFieldValue
{
    /**
     * @throws InvalidCustomFieldValueException If value not in allowedValues for Choice/List types
     */
    public function __construct(
        CustomFieldDefinition $definition,
        public string $value,
    ) {
        Assert::true(
            $definition->type->isStringType(),
            "StringCustomFieldValue requires string type (Text/Choice/List), got '{$definition->type->value}'",
        );

        // Validate Choice/List values against allowedValues
        if ($definition->hasAllowedValues() && !$definition->isValueAllowed($value)) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->name,
                expectedType: $definition->type,
                actualType: 'string (invalid choice)',
                rawValue: $value,
            );
        }

        parent::__construct($definition);
    }

    public function rawValue(): string
    {
        return $this->value;
    }
}
