<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use App\Domain\ValueObjects\Uuid;
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
        $definition = self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'is_featured',
            type: CustomFieldType::Toggle,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("StringCustomFieldValue requires string type (Text/Choice/List), got 'toggle'");

        new StringCustomFieldValue($definition, 'test');
    }

    #[Test]
    public function it_rejects_date_type(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'release_date',
            type: CustomFieldType::Date,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("StringCustomFieldValue requires string type (Text/Choice/List), got 'date'");

        new StringCustomFieldValue($definition, 'test');
    }

    #[Test]
    public function it_rejects_value_list_type(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'tags',
            type: CustomFieldType::ValueList,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("StringCustomFieldValue requires string type (Text/Choice/List), got 'value_list'");

        new StringCustomFieldValue($definition, 'test');
    }

    #[Test]
    public function it_rejects_product_list_type(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'related',
            type: CustomFieldType::ProductList,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("StringCustomFieldValue requires string type (Text/Choice/List), got 'product_list'");

        new StringCustomFieldValue($definition, 'test');
    }

    // ========================================================================
    // Stale Choice Values (read-path tolerance)
    // ========================================================================

    #[Test]
    public function it_accepts_value_not_in_allowed_list(): void
    {
        $definition = $this->createChoiceDefinition(['Red', 'Green', 'Blue']);

        // 'Yellow' was valid when saved but later removed from allowedValues.
        // The VO must tolerate stale data so the read path never 500s.
        $value = new StringCustomFieldValue($definition, 'Yellow');

        self::assertSame('Yellow', $value->value);
        self::assertSame('Yellow', $value->rawValue());
    }

    #[Test]
    public function it_accepts_stale_list_value(): void
    {
        $definition = $this->createListDefinition(['Small', 'Medium', 'Large']);

        $value = new StringCustomFieldValue($definition, 'Extra Large');

        self::assertSame('Extra Large', $value->value);
    }

    // ========================================================================
    // toArray - AbstractCustomFieldValue
    // ========================================================================

    #[Test]
    public function to_array_includes_all_definition_metadata(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 10,
            name: 'material',
            type: CustomFieldType::Text,
            label: 'Material',
            itemType: CustomFieldItemType::Product,
            sortOrder: 3,
            allowedValues: null,
        ));
        $value = new StringCustomFieldValue($definition, 'Cotton');

        $result = $value->toArray();

        self::assertSame('material', $result['name']);
        self::assertSame('text', $result['type']);
        self::assertSame('Material', $result['label']);
        self::assertSame('Cotton', $result['value']);
        self::assertNull($result['allowed_values']);
        self::assertSame(3, $result['sort_order']);
    }

    #[Test]
    public function to_array_includes_null_label_and_sort_order(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 11,
            name: 'notes',
            type: CustomFieldType::Text,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        ));
        $value = new StringCustomFieldValue($definition, 'some note');

        $result = $value->toArray();

        self::assertNull($result['label']);
        self::assertNull($result['sort_order']);
        self::assertSame('some note', $result['value']);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createTextDefinition(): ConfiguredFieldDefinition
    {
        return self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'product_notes',
            type: CustomFieldType::Text,
            label: 'Product Notes',
            itemType: CustomFieldItemType::Product,
            sortOrder: 0,
            allowedValues: null,
        ));
    }

    /**
     * @param list<string> $allowedValues
     */
    private function createChoiceDefinition(array $allowedValues): ConfiguredFieldDefinition
    {
        return self::wrap(new CustomFieldDefinition(
            id: 2,
            name: 'color',
            type: CustomFieldType::Choice,
            label: 'Color',
            itemType: CustomFieldItemType::Product,
            sortOrder: 1,
            allowedValues: $allowedValues,
        ));
    }

    /**
     * @param list<string> $allowedValues
     */
    private function createListDefinition(array $allowedValues): ConfiguredFieldDefinition
    {
        return self::wrap(new CustomFieldDefinition(
            id: 3,
            name: 'size',
            type: CustomFieldType::List,
            label: 'Size',
            itemType: CustomFieldItemType::Product,
            sortOrder: 2,
            allowedValues: $allowedValues,
        ));
    }

    private static function wrap(CustomFieldDefinition $base): ConfiguredFieldDefinition
    {
        return new ConfiguredFieldDefinition(
            new Uuid('11111111-2222-3333-4444-555555555555'),
            $base,
            null,
            null,
        );
    }
}
