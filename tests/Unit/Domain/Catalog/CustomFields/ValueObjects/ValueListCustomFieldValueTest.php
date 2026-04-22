<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\ValueListCustomFieldValue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValueListCustomFieldValue::class)]
final class ValueListCustomFieldValueTest extends TestCase
{
    // ========================================================================
    // Happy Path
    // ========================================================================

    #[Test]
    public function it_creates_valid_value_list(): void
    {
        $definition = $this->createValueListDefinition();
        $value = new ValueListCustomFieldValue($definition, ['tag1', 'tag2', 'tag3']);

        self::assertSame(['tag1', 'tag2', 'tag3'], $value->values);
        self::assertSame(['tag1', 'tag2', 'tag3'], $value->rawValue());
    }

    #[Test]
    public function it_allows_empty_array(): void
    {
        $definition = $this->createValueListDefinition();
        $value = new ValueListCustomFieldValue($definition, []);

        self::assertSame([], $value->values);
        self::assertSame([], $value->rawValue());
    }

    #[Test]
    public function it_allows_single_value(): void
    {
        $definition = $this->createValueListDefinition();
        $value = new ValueListCustomFieldValue($definition, ['only-one']);

        self::assertSame(['only-one'], $value->values);
    }

    // ========================================================================
    // isEmpty Helper
    // ========================================================================

    #[Test]
    public function is_empty_returns_true_for_empty_array(): void
    {
        $definition = $this->createValueListDefinition();
        $value = new ValueListCustomFieldValue($definition, []);

        self::assertTrue($value->isEmpty());
    }

    #[Test]
    public function is_empty_returns_false_for_non_empty_array(): void
    {
        $definition = $this->createValueListDefinition();
        $value = new ValueListCustomFieldValue($definition, ['value']);

        self::assertFalse($value->isEmpty());
    }

    // ========================================================================
    // count Helper
    // ========================================================================

    #[Test]
    public function count_returns_zero_for_empty_array(): void
    {
        $definition = $this->createValueListDefinition();
        $value = new ValueListCustomFieldValue($definition, []);

        self::assertSame(0, $value->count());
    }

    #[Test]
    public function count_returns_correct_value(): void
    {
        $definition = $this->createValueListDefinition();
        $value = new ValueListCustomFieldValue($definition, ['a', 'b', 'c', 'd', 'e']);

        self::assertSame(5, $value->count());
    }

    // ========================================================================
    // Inherited Methods from Abstract
    // ========================================================================

    #[Test]
    public function name_returns_definition_name(): void
    {
        $definition = $this->createValueListDefinition();
        $value = new ValueListCustomFieldValue($definition, []);

        self::assertSame('tags', $value->name());
    }

    #[Test]
    public function label_returns_definition_label(): void
    {
        $definition = $this->createValueListDefinition();
        $value = new ValueListCustomFieldValue($definition, []);

        self::assertSame('Product Tags', $value->label());
    }

    #[Test]
    public function type_returns_value_list(): void
    {
        $definition = $this->createValueListDefinition();
        $value = new ValueListCustomFieldValue($definition, []);

        self::assertSame(CustomFieldType::ValueList, $value->type());
    }

    // ========================================================================
    // Validation - Type Mismatch
    // ========================================================================

    #[Test]
    public function it_rejects_text_type(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'notes',
            type: CustomFieldType::Text,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("ValueListCustomFieldValue requires ValueList type, got 'text'");

        new ValueListCustomFieldValue($definition, []);
    }

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
        $this->expectExceptionMessage("ValueListCustomFieldValue requires ValueList type, got 'toggle'");

        new ValueListCustomFieldValue($definition, []);
    }

    #[Test]
    public function it_rejects_choice_type(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'color',
            type: CustomFieldType::Choice,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: ['Red', 'Blue'],
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("ValueListCustomFieldValue requires ValueList type, got 'choice'");

        new ValueListCustomFieldValue($definition, ['Red']);
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
        $this->expectExceptionMessage("ValueListCustomFieldValue requires ValueList type, got 'product_list'");

        new ValueListCustomFieldValue($definition, []);
    }

    // ========================================================================
    // Validation - Array Content
    // ========================================================================

    #[Test]
    public function it_rejects_array_with_non_string_values(): void
    {
        $definition = $this->createValueListDefinition();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ValueList values must all be strings');

        // @phpstan-ignore argument.type (testing runtime validation)
        new ValueListCustomFieldValue($definition, ['valid', 123, 'also valid']);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createValueListDefinition(): ConfiguredFieldDefinition
    {
        return self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'tags',
            type: CustomFieldType::ValueList,
            label: 'Product Tags',
            itemType: CustomFieldItemType::Product,
            sortOrder: 0,
            allowedValues: null,
        ));
    }

    private static function wrap(CustomFieldDefinition $base): ConfiguredFieldDefinition
    {
        return new ConfiguredFieldDefinition($base, null, null);
    }
}
