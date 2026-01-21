<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomFieldDefinition::class)]
final class CustomFieldDefinitionTest extends TestCase
{
    // ========================================================================
    // Happy Path
    // ========================================================================

    #[Test]
    public function it_creates_valid_text_field(): void
    {
        $definition = new CustomFieldDefinition(
            id: 1,
            name: 'product_notes',
            type: CustomFieldType::Text,
            label: 'Product Notes',
            itemType: CustomFieldItemType::Product,
            sortOrder: 0,
            allowedValues: null,
        );

        self::assertSame(1, $definition->id);
        self::assertSame('product_notes', $definition->name);
        self::assertSame(CustomFieldType::Text, $definition->type);
        self::assertSame('Product Notes', $definition->label);
        self::assertSame(CustomFieldItemType::Product, $definition->itemType);
        self::assertSame(0, $definition->sortOrder);
        self::assertNull($definition->allowedValues);
    }

    #[Test]
    public function it_creates_valid_choice_field_with_allowed_values(): void
    {
        $definition = new CustomFieldDefinition(
            id: 2,
            name: 'color',
            type: CustomFieldType::Choice,
            label: 'Color',
            itemType: CustomFieldItemType::Product,
            sortOrder: 1,
            allowedValues: ['Red', 'Green', 'Blue'],
        );

        self::assertSame(['Red', 'Green', 'Blue'], $definition->allowedValues);
    }

    #[Test]
    public function it_allows_null_label(): void
    {
        $definition = new CustomFieldDefinition(
            id: 1,
            name: 'internal_code',
            type: CustomFieldType::Text,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        );

        self::assertNull($definition->label);
    }

    // ========================================================================
    // Validation
    // ========================================================================

    #[Test]
    public function it_rejects_non_positive_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom field ID must be positive');

        new CustomFieldDefinition(
            id: 0,
            name: 'field',
            type: CustomFieldType::Text,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        );
    }

    #[Test]
    public function it_rejects_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom field name cannot be empty');

        new CustomFieldDefinition(
            id: 1,
            name: '',
            type: CustomFieldType::Text,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        );
    }

    #[Test]
    public function it_rejects_name_exceeding_40_chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom field name cannot exceed 40 characters');

        new CustomFieldDefinition(
            id: 1,
            name: \str_repeat('a', 41),
            type: CustomFieldType::Text,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        );
    }

    #[Test]
    public function it_rejects_choice_type_without_allowed_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Choice/list types require allowedValues');

        new CustomFieldDefinition(
            id: 1,
            name: 'color',
            type: CustomFieldType::Choice,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        );
    }

    #[Test]
    public function it_rejects_choice_type_with_empty_allowed_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Choice/list types require at least one allowedValue');

        new CustomFieldDefinition(
            id: 1,
            name: 'color',
            type: CustomFieldType::Choice,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: [],
        );
    }

    #[Test]
    public function it_rejects_list_type_without_allowed_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Choice/list types require allowedValues');

        new CustomFieldDefinition(
            id: 1,
            name: 'size',
            type: CustomFieldType::List,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        );
    }

    // ========================================================================
    // hasAllowedValues
    // ========================================================================

    #[Test]
    public function has_allowed_values_returns_true_when_values_exist(): void
    {
        $definition = new CustomFieldDefinition(
            id: 1,
            name: 'color',
            type: CustomFieldType::Choice,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: ['Red', 'Blue'],
        );

        self::assertTrue($definition->hasAllowedValues());
    }

    #[Test]
    public function has_allowed_values_returns_false_when_null(): void
    {
        $definition = new CustomFieldDefinition(
            id: 1,
            name: 'notes',
            type: CustomFieldType::Text,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        );

        self::assertFalse($definition->hasAllowedValues());
    }

    // ========================================================================
    // isValueAllowed
    // ========================================================================

    #[Test]
    public function is_value_allowed_returns_true_for_valid_choice(): void
    {
        $definition = new CustomFieldDefinition(
            id: 1,
            name: 'color',
            type: CustomFieldType::Choice,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: ['Red', 'Green', 'Blue'],
        );

        self::assertTrue($definition->isValueAllowed('Red'));
        self::assertTrue($definition->isValueAllowed('Green'));
        self::assertTrue($definition->isValueAllowed('Blue'));
    }

    #[Test]
    public function is_value_allowed_returns_false_for_invalid_choice(): void
    {
        $definition = new CustomFieldDefinition(
            id: 1,
            name: 'color',
            type: CustomFieldType::Choice,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: ['Red', 'Green', 'Blue'],
        );

        self::assertFalse($definition->isValueAllowed('Yellow'));
    }

    #[Test]
    public function is_value_allowed_returns_true_when_no_restrictions(): void
    {
        $definition = new CustomFieldDefinition(
            id: 1,
            name: 'notes',
            type: CustomFieldType::Text,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        );

        self::assertTrue($definition->isValueAllowed('anything'));
    }

    // ========================================================================
    // Item Type Helpers
    // ========================================================================

    #[Test]
    public function is_product_field_returns_true_for_product_type(): void
    {
        $definition = new CustomFieldDefinition(
            id: 1,
            name: 'field',
            type: CustomFieldType::Text,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        );

        self::assertTrue($definition->isProductField());
        self::assertFalse($definition->isCustomerField());
        self::assertFalse($definition->isOrderField());
    }

    #[Test]
    public function is_customer_field_returns_true_for_customer_type(): void
    {
        $definition = new CustomFieldDefinition(
            id: 1,
            name: 'field',
            type: CustomFieldType::Text,
            label: null,
            itemType: CustomFieldItemType::Customer,
            sortOrder: null,
            allowedValues: null,
        );

        self::assertTrue($definition->isCustomerField());
        self::assertFalse($definition->isProductField());
        self::assertFalse($definition->isOrderField());
    }

    #[Test]
    public function is_order_field_returns_true_for_order_type(): void
    {
        $definition = new CustomFieldDefinition(
            id: 1,
            name: 'field',
            type: CustomFieldType::Text,
            label: null,
            itemType: CustomFieldItemType::Order,
            sortOrder: null,
            allowedValues: null,
        );

        self::assertTrue($definition->isOrderField());
        self::assertFalse($definition->isProductField());
        self::assertFalse($definition->isCustomerField());
    }
}
