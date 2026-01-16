<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use Webmozart\Assert\Assert;

/**
 * Custom field definition from ShopWired.
 *
 * Represents the schema/metadata for a custom field, not its value.
 * These definitions determine how custom field values should be
 * interpreted and validated when attached to products, customers, etc.
 *
 * @see https://shopwired.readme.io/reference/listcustomfields
 */
final readonly class CustomFieldDefinition
{
    /**
     * @param int $id ShopWired custom field ID
     * @param string $name Field identifier (snake_case, max 40 chars)
     * @param CustomFieldType $type Data type determining value interpretation
     * @param string|null $label Human-readable display label (may be null)
     * @param CustomFieldItemType $itemType Entity type this field applies to
     * @param int|null $sortOrder Display ordering (lower = first, null if unset)
     * @param list<string>|null $allowedValues Valid values for choice/list types
     */
    public function __construct(
        public int $id,
        public string $name,
        public CustomFieldType $type,
        public ?string $label,
        public CustomFieldItemType $itemType,
        public ?int $sortOrder,
        public ?array $allowedValues,
    ) {
        Assert::greaterThan($id, 0, 'Custom field ID must be positive');
        Assert::notEmpty($name, 'Custom field name cannot be empty');
        Assert::maxLength($name, 40, 'Custom field name cannot exceed 40 characters');

        // Validate allowedValues consistency with type
        if ($type->requiresAllowedValues()) {
            Assert::notNull($allowedValues, 'Choice/list types require allowedValues');
            Assert::notEmpty($allowedValues, 'Choice/list types require at least one allowedValue');
        }
    }

    /**
     * Check if this field has constrained allowed values.
     */
    public function hasAllowedValues(): bool
    {
        return $this->allowedValues !== null && $this->allowedValues !== [];
    }

    /**
     * Check if a value is valid for this field (for choice/list types).
     */
    public function isValueAllowed(string $value): bool
    {
        if ($this->allowedValues === null || $this->allowedValues === []) {
            return true;
        }

        return \in_array($value, $this->allowedValues, true);
    }

    /**
     * Check if this field applies to products.
     */
    public function isProductField(): bool
    {
        return $this->itemType === CustomFieldItemType::Product;
    }

    /**
     * Check if this field applies to customers.
     */
    public function isCustomerField(): bool
    {
        return $this->itemType === CustomFieldItemType::Customer;
    }

    /**
     * Check if this field applies to orders.
     */
    public function isOrderField(): bool
    {
        return $this->itemType === CustomFieldItemType::Order;
    }
}
