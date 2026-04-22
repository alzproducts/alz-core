<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldGeneralSettings;
use App\Domain\Catalog\CustomFields\ValueObjects\ToggleCustomFieldValue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToggleCustomFieldValue::class)]
final class ToggleCustomFieldValueTest extends TestCase
{
    // ========================================================================
    // Happy Path
    // ========================================================================

    #[Test]
    public function it_creates_value_with_true(): void
    {
        $definition = $this->createToggleDefinition();
        $value = new ToggleCustomFieldValue($definition, true);

        self::assertTrue($value->value);
        self::assertTrue($value->rawValue());
    }

    #[Test]
    public function it_creates_value_with_false(): void
    {
        $definition = $this->createToggleDefinition();
        $value = new ToggleCustomFieldValue($definition, false);

        self::assertFalse($value->value);
        self::assertFalse($value->rawValue());
    }

    // ========================================================================
    // Inherited Methods from Abstract
    // ========================================================================

    #[Test]
    public function name_returns_definition_name(): void
    {
        $definition = $this->createToggleDefinition();
        $value = new ToggleCustomFieldValue($definition, true);

        self::assertSame('is_featured', $value->name());
    }

    #[Test]
    public function label_returns_definition_label(): void
    {
        $definition = $this->createToggleDefinition();
        $value = new ToggleCustomFieldValue($definition, true);

        self::assertSame('Featured Product', $value->label());
    }

    #[Test]
    public function label_can_be_null(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'internal_flag',
            type: CustomFieldType::Toggle,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        ));
        $value = new ToggleCustomFieldValue($definition, false);

        self::assertNull($value->label());
    }

    #[Test]
    public function type_returns_toggle(): void
    {
        $definition = $this->createToggleDefinition();
        $value = new ToggleCustomFieldValue($definition, true);

        self::assertSame(CustomFieldType::Toggle, $value->type());
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
        $this->expectExceptionMessage("ToggleCustomFieldValue requires boolean type (Toggle), got 'text'");

        new ToggleCustomFieldValue($definition, true);
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
        $this->expectExceptionMessage("ToggleCustomFieldValue requires boolean type (Toggle), got 'choice'");

        new ToggleCustomFieldValue($definition, false);
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
        $this->expectExceptionMessage("ToggleCustomFieldValue requires boolean type (Toggle), got 'date'");

        new ToggleCustomFieldValue($definition, true);
    }

    #[Test]
    public function it_rejects_date_time_type(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'published_at',
            type: CustomFieldType::DateTime,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("ToggleCustomFieldValue requires boolean type (Toggle), got 'date_time'");

        new ToggleCustomFieldValue($definition, false);
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
        $this->expectExceptionMessage("ToggleCustomFieldValue requires boolean type (Toggle), got 'value_list'");

        new ToggleCustomFieldValue($definition, true);
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
        $this->expectExceptionMessage("ToggleCustomFieldValue requires boolean type (Toggle), got 'product_list'");

        new ToggleCustomFieldValue($definition, false);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createToggleDefinition(): ConfiguredFieldDefinition
    {
        return self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'is_featured',
            type: CustomFieldType::Toggle,
            label: 'Featured Product',
            itemType: CustomFieldItemType::Product,
            sortOrder: 0,
            allowedValues: null,
        ));
    }

    private static function wrap(CustomFieldDefinition $base): ConfiguredFieldDefinition
    {
        return new ConfiguredFieldDefinition($base, CustomFieldGeneralSettings::defaults(), null);
    }
}
