<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\NullCustomFieldValue;
use App\Domain\ValueObjects\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NullCustomFieldValue::class)]
final class NullCustomFieldValueTest extends TestCase
{
    #[Test]
    public function raw_value_returns_null(): void
    {
        $value = new NullCustomFieldValue($this->createDefinition());

        self::assertNull($value->rawValue());
    }

    #[Test]
    public function name_delegates_to_definition(): void
    {
        $value = new NullCustomFieldValue($this->createDefinition());

        self::assertSame('color', $value->name());
    }

    #[Test]
    public function label_delegates_to_definition(): void
    {
        $value = new NullCustomFieldValue($this->createDefinition());

        self::assertSame('Color', $value->label());
    }

    #[Test]
    public function type_delegates_to_definition(): void
    {
        $value = new NullCustomFieldValue($this->createDefinition());

        self::assertSame(CustomFieldType::Choice, $value->type());
    }

    #[Test]
    public function to_array_includes_null_value_with_definition_metadata(): void
    {
        $value = new NullCustomFieldValue($this->createDefinition());

        $result = $value->toArray();

        self::assertSame('color', $result['name']);
        self::assertSame('choice', $result['type']);
        self::assertSame('Color', $result['label']);
        self::assertNull($result['value']);
        self::assertSame(['Red', 'Green', 'Blue'], $result['allowed_values']);
        self::assertSame(1, $result['sort_order']);
    }

    private function createDefinition(): ConfiguredFieldDefinition
    {
        return new ConfiguredFieldDefinition(
            new Uuid('11111111-2222-3333-4444-555555555555'),
            new CustomFieldDefinition(
                id: 1,
                name: 'color',
                type: CustomFieldType::Choice,
                label: 'Color',
                itemType: CustomFieldItemType::Product,
                sortOrder: 1,
                allowedValues: ['Red', 'Green', 'Blue'],
            ),
            null,
            null,
        );
    }
}
