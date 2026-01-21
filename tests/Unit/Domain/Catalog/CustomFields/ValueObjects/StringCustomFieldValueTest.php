<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StringCustomFieldValue::class)]
final class StringCustomFieldValueTest extends TestCase
{
    // ========================================================================
    // Happy Path - Text Type
    // ========================================================================

    #[Test]
    public function it_creates_valid_text_value(): void
    {
        $definition = $this->createTextDefinition();
        $value = new StringCustomFieldValue($definition, 'Some text content');

        self::assertSame('Some text content', $value->value);
        self::assertSame('Some text content', $value->rawValue());
    }

    #[Test]
    public function it_allows_empty_string_for_text_type(): void
    {
        $definition = $this->createTextDefinition();
        $value = new StringCustomFieldValue($definition, '');

        self::assertSame('', $value->value);
    }

    // ========================================================================
    // Happy Path - Choice Type
    // ========================================================================

    #[Test]
    public function it_creates_valid_choice_value(): void
    {
        $definition = $this->createChoiceDefinition(['Red', 'Green', 'Blue']);
        $value = new StringCustomFieldValue($definition, 'Red');

        self::assertSame('Red', $value->value);
        self::assertSame('Red', $value->rawValue());
    }

    #[Test]
    public function it_accepts_all_allowed_choice_values(): void
    {
        $definition = $this->createChoiceDefinition(['Option A', 'Option B', 'Option C']);

        $valueA = new StringCustomFieldValue($definition, 'Option A');
        $valueB = new StringCustomFieldValue($definition, 'Option B');
        $valueC = new StringCustomFieldValue($definition, 'Option C');

        self::assertSame('Option A', $valueA->value);
        self::assertSame('Option B', $valueB->value);
        self::assertSame('Option C', $valueC->value);
    }

    // ========================================================================
    // Happy Path - List Type
    // ========================================================================

    #[Test]
    public function it_creates_valid_list_value(): void
    {
        $definition = $this->createListDefinition(['Small', 'Medium', 'Large']);
        $value = new StringCustomFieldValue($definition, 'Medium');

        self::assertSame('Medium', $value->value);
        self::assertSame('Medium', $value->rawValue());
    }

    // ========================================================================
    // Inherited Methods from Abstract
    // ========================================================================

    #[Test]
    public function name_returns_definition_name(): void
    {
        $definition = $this->createTextDefinition();
        $value = new StringCustomFieldValue($definition, 'test');

        self::assertSame('product_notes', $value->name());
    }

    #[Test]
    public function label_returns_definition_label(): void
    {
        $definition = $this->createTextDefinition();
        $value = new StringCustomFieldValue($definition, 'test');

        self::assertSame('Product Notes', $value->label());
    }

    #[Test]
    public function type_returns_definition_type(): void
    {
        $definition = $this->createTextDefinition();
        $value = new StringCustomFieldValue($definition, 'test');

        self::assertSame(CustomFieldType::Text, $value->type());
    }

    // ========================================================================
    // Validation - Type Mismatch
    // ========================================================================

    #[Test]
    public function it_rejects_toggle_type(): void
    {
        $definition = new CustomFieldDefinition(
            id: 1,
            name: 'is_featured',
            type: CustomFieldType::Toggle,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("StringCustomFieldValue requires string type (Text/Choice/List), got 'toggle'");

        new StringCustomFieldValue($definition, 'test');
    }

    #[Test]
    public function it_rejects_date_type(): void
    {
        $definition = new CustomFieldDefinition(
            id: 1,
            name: 'release_date',
            type: CustomFieldType::Date,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("StringCustomFieldValue requires string type (Text/Choice/List), got 'date'");

        new StringCustomFieldValue($definition, 'test');
    }

    #[Test]
    public function it_rejects_value_list_type(): void
    {
        $definition = new CustomFieldDefinition(
            id: 1,
            name: 'tags',
            type: CustomFieldType::ValueList,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("StringCustomFieldValue requires string type (Text/Choice/List), got 'value_list'");

        new StringCustomFieldValue($definition, 'test');
    }

    #[Test]
    public function it_rejects_product_list_type(): void
    {
        $definition = new CustomFieldDefinition(
            id: 1,
            name: 'related',
            type: CustomFieldType::ProductList,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("StringCustomFieldValue requires string type (Text/Choice/List), got 'product_list'");

        new StringCustomFieldValue($definition, 'test');
    }

    // ========================================================================
    // Validation - Invalid Choice Value
    // ========================================================================

    #[Test]
    public function it_rejects_invalid_choice_value(): void
    {
        $definition = $this->createChoiceDefinition(['Red', 'Green', 'Blue']);

        $this->expectException(InvalidCustomFieldValueException::class);

        new StringCustomFieldValue($definition, 'Yellow');
    }

    #[Test]
    public function it_rejects_invalid_list_value(): void
    {
        $definition = $this->createListDefinition(['Small', 'Medium', 'Large']);

        $this->expectException(InvalidCustomFieldValueException::class);

        new StringCustomFieldValue($definition, 'Extra Large');
    }

    #[Test]
    public function invalid_choice_exception_has_correct_properties(): void
    {
        $definition = $this->createChoiceDefinition(['Red', 'Green', 'Blue']);

        try {
            new StringCustomFieldValue($definition, 'Yellow');
            self::fail('Expected InvalidCustomFieldValueException');
        } catch (InvalidCustomFieldValueException $e) {
            self::assertSame('color', $e->fieldName);
            self::assertSame(CustomFieldType::Choice, $e->expectedType);
            self::assertSame('string (invalid choice)', $e->actualType);
            self::assertSame('Yellow', $e->rawValue);
        }
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createTextDefinition(): CustomFieldDefinition
    {
        return new CustomFieldDefinition(
            id: 1,
            name: 'product_notes',
            type: CustomFieldType::Text,
            label: 'Product Notes',
            itemType: CustomFieldItemType::Product,
            sortOrder: 0,
            allowedValues: null,
        );
    }

    private function createChoiceDefinition(array $allowedValues): CustomFieldDefinition
    {
        return new CustomFieldDefinition(
            id: 2,
            name: 'color',
            type: CustomFieldType::Choice,
            label: 'Color',
            itemType: CustomFieldItemType::Product,
            sortOrder: 1,
            allowedValues: $allowedValues,
        );
    }

    private function createListDefinition(array $allowedValues): CustomFieldDefinition
    {
        return new CustomFieldDefinition(
            id: 3,
            name: 'size',
            type: CustomFieldType::List,
            label: 'Size',
            itemType: CustomFieldItemType::Product,
            sortOrder: 2,
            allowedValues: $allowedValues,
        );
    }
}
