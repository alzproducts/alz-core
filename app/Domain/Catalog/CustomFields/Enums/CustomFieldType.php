<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\Enums;

/**
 * Custom field data types as defined by ShopWired.
 *
 * Determines how the field value should be interpreted and validated.
 *
 * @see https://shopwired.readme.io/reference/listcustomfields
 */
enum CustomFieldType: string
{
    /** Free-form text input */
    case Text = 'text';

    /** Boolean toggle (true/false) */
    case Toggle = 'toggle';

    /** Radio button selection (single choice from allowedValues) */
    case Choice = 'choice';

    /** Dropdown selection (single choice from allowedValues) */
    case List = 'list';

    /** Date only (no time component) */
    case Date = 'date';

    /** Date with time */
    case DateTime = 'date_time';

    /** User-entered array of string values */
    case ValueList = 'value_list';

    /** Array of ShopWired product IDs */
    case ProductList = 'product_list';

    /**
     * Check if this type requires allowedValues to be defined.
     */
    public function requiresAllowedValues(): bool
    {
        return $this === self::Choice || $this === self::List;
    }

    /**
     * Check if this type produces an array value.
     */
    public function isArrayType(): bool
    {
        return $this === self::ValueList || $this === self::ProductList;
    }

    /**
     * Check if this type produces a string value.
     */
    public function isStringType(): bool
    {
        return $this === self::Text || $this === self::Choice || $this === self::List;
    }

    /**
     * Check if this type produces a boolean value.
     */
    public function isBooleanType(): bool
    {
        return $this === self::Toggle;
    }

    /**
     * Check if this type produces a date/datetime value.
     */
    public function isDateType(): bool
    {
        return $this === self::Date || $this === self::DateTime;
    }
}
