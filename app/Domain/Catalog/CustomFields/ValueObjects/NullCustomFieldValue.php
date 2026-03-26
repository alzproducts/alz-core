<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\ValueObjects;

/**
 * Represents a custom field with no value set on the entity.
 *
 * Used when returning all defined fields to the frontend — fields without
 * data on the product are represented as NullCustomFieldValue so the
 * frontend can still render the input with label, type, and allowedValues.
 */
final readonly class NullCustomFieldValue extends AbstractCustomFieldValue
{
    public function rawValue(): null
    {
        return null;
    }
}
