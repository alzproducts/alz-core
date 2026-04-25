<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use DateTimeImmutable;

/**
 * Abstract base for typed custom field values.
 *
 * Represents a custom field value with its embedded configured definition,
 * providing complete context (label, type, allowedValues) without additional lookups.
 *
 * Entity-agnostic: can be used for Products, Customers, Orders, etc.
 *
 * Subtypes:
 * - StringCustomFieldValue: Text, Choice, List (string value)
 * - DateTimeCustomFieldValue: Date, DateTime (DateTimeImmutable value)
 * - ToggleCustomFieldValue: Boolean toggle
 * - ValueListCustomFieldValue: Array of strings
 * - ProductListCustomFieldValue: Array of product IDs
 */
abstract readonly class AbstractCustomFieldValue
{
    public function __construct(
        public ConfiguredFieldDefinition $definition,
    ) {}

    /**
     * Get the raw value for serialization/storage.
     *
     * @return string|bool|DateTimeImmutable|list<string>|list<int>|null
     */
    abstract public function rawValue(): string|bool|array|DateTimeImmutable|null;

    /**
     * Field name (identifier).
     */
    public function name(): string
    {
        return $this->definition->base->name;
    }

    /**
     * Human-readable label (may be null).
     */
    public function label(): ?string
    {
        return $this->definition->base->label;
    }

    /**
     * Field type enum.
     */
    public function type(): CustomFieldType
    {
        return $this->definition->base->type;
    }
}
