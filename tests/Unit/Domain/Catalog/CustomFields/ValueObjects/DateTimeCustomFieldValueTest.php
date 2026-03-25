<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\DateTimeCustomFieldValue;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DateTimeCustomFieldValue::class)]
final class DateTimeCustomFieldValueTest extends TestCase
{
    // ========================================================================
    // Happy Path - Direct Construction
    // ========================================================================

    #[Test]
    public function it_creates_valid_date_value(): void
    {
        $definition = $this->createDateDefinition();
        $dateTime = new DateTimeImmutable('2024-06-15');
        $value = new DateTimeCustomFieldValue($definition, $dateTime);

        self::assertSame($dateTime, $value->value);
        self::assertSame($dateTime, $value->rawValue());
    }

    #[Test]
    public function it_creates_valid_datetime_value(): void
    {
        $definition = $this->createDateTimeDefinition();
        $dateTime = new DateTimeImmutable('2024-06-15 14:30:00');
        $value = new DateTimeCustomFieldValue($definition, $dateTime);

        self::assertSame($dateTime, $value->value);
        self::assertSame($dateTime, $value->rawValue());
    }

    // ========================================================================
    // Happy Path - fromTimestamp Factory
    // ========================================================================

    #[Test]
    public function from_timestamp_creates_value_in_london_timezone(): void
    {
        $definition = $this->createDateTimeDefinition();
        // Use a known timestamp: 2024-06-15 12:00:00 UTC
        $utcDateTime = new DateTimeImmutable('2024-06-15 12:00:00', new DateTimeZone('UTC'));
        $timestamp = $utcDateTime->getTimestamp();

        $value = DateTimeCustomFieldValue::fromTimestamp($definition, $timestamp);

        self::assertSame('Europe/London', $value->value->getTimezone()->getName());
        // In BST (summer time), London is UTC+1, so 12:00 UTC = 13:00 London
        self::assertSame('2024-06-15 13:00:00', $value->value->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function from_timestamp_handles_winter_time(): void
    {
        $definition = $this->createDateTimeDefinition();
        // Use a known timestamp: 2024-01-15 12:00:00 UTC (winter, no DST)
        $utcDateTime = new DateTimeImmutable('2024-01-15 12:00:00', new DateTimeZone('UTC'));
        $timestamp = $utcDateTime->getTimestamp();

        $value = DateTimeCustomFieldValue::fromTimestamp($definition, $timestamp);

        self::assertSame('Europe/London', $value->value->getTimezone()->getName());
        // In GMT (winter time), London is UTC+0, so 12:00 UTC = 12:00 London
        self::assertSame('2024-01-15 12:00:00', $value->value->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function from_timestamp_works_with_date_type(): void
    {
        $definition = $this->createDateDefinition();
        $value = DateTimeCustomFieldValue::fromTimestamp($definition, 1718409600);

        self::assertSame('release_date', $value->name());
        self::assertSame(CustomFieldType::Date, $value->type());
    }

    #[Test]
    public function from_timestamp_handles_epoch(): void
    {
        $definition = $this->createDateTimeDefinition();
        $value = DateTimeCustomFieldValue::fromTimestamp($definition, 0);

        // Epoch (1970-01-01 00:00:00 UTC) in London timezone
        self::assertSame('1970-01-01 01:00:00', $value->value->format('Y-m-d H:i:s'));
    }

    // ========================================================================
    // Inherited Methods from Abstract
    // ========================================================================

    #[Test]
    public function name_returns_definition_name(): void
    {
        $definition = $this->createDateDefinition();
        $value = new DateTimeCustomFieldValue($definition, new DateTimeImmutable());

        self::assertSame('release_date', $value->name());
    }

    #[Test]
    public function label_returns_definition_label(): void
    {
        $definition = $this->createDateDefinition();
        $value = new DateTimeCustomFieldValue($definition, new DateTimeImmutable());

        self::assertSame('Release Date', $value->label());
    }

    #[Test]
    public function type_returns_date_type(): void
    {
        $definition = $this->createDateDefinition();
        $value = new DateTimeCustomFieldValue($definition, new DateTimeImmutable());

        self::assertSame(CustomFieldType::Date, $value->type());
    }

    #[Test]
    public function type_returns_datetime_type(): void
    {
        $definition = $this->createDateTimeDefinition();
        $value = new DateTimeCustomFieldValue($definition, new DateTimeImmutable());

        self::assertSame(CustomFieldType::DateTime, $value->type());
    }

    // ========================================================================
    // toArray - DateTimeCustomFieldValue Override
    // ========================================================================

    #[Test]
    public function to_array_formats_value_as_atom_string(): void
    {
        $definition = $this->createDateTimeDefinition();
        $dateTime = new DateTimeImmutable('2024-06-15T14:30:00+00:00');
        $value = new DateTimeCustomFieldValue($definition, $dateTime);

        $result = $value->toArray();

        self::assertSame($dateTime->format(DateTimeInterface::ATOM), $result['value']);
        self::assertIsString($result['value']);
    }

    #[Test]
    public function to_array_includes_definition_metadata(): void
    {
        $definition = $this->createDateTimeDefinition();
        $dateTime = new DateTimeImmutable('2024-06-15T14:30:00+00:00');
        $value = new DateTimeCustomFieldValue($definition, $dateTime);

        $result = $value->toArray();

        self::assertSame('published_at', $result['name']);
        self::assertSame('date_time', $result['type']);
        self::assertSame('Published At', $result['label']);
        self::assertNull($result['allowed_values']);
        self::assertSame(1, $result['sort_order']);
    }

    // ========================================================================
    // Validation - Type Mismatch
    // ========================================================================

    #[Test]
    public function it_rejects_text_type(): void
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

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("DateTimeCustomFieldValue requires date type (Date/DateTime), got 'text'");

        new DateTimeCustomFieldValue($definition, new DateTimeImmutable());
    }

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
        $this->expectExceptionMessage("DateTimeCustomFieldValue requires date type (Date/DateTime), got 'toggle'");

        new DateTimeCustomFieldValue($definition, new DateTimeImmutable());
    }

    #[Test]
    public function it_rejects_choice_type(): void
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

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("DateTimeCustomFieldValue requires date type (Date/DateTime), got 'choice'");

        new DateTimeCustomFieldValue($definition, new DateTimeImmutable());
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
        $this->expectExceptionMessage("DateTimeCustomFieldValue requires date type (Date/DateTime), got 'value_list'");

        new DateTimeCustomFieldValue($definition, new DateTimeImmutable());
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
        $this->expectExceptionMessage("DateTimeCustomFieldValue requires date type (Date/DateTime), got 'product_list'");

        new DateTimeCustomFieldValue($definition, new DateTimeImmutable());
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createDateDefinition(): CustomFieldDefinition
    {
        return new CustomFieldDefinition(
            id: 1,
            name: 'release_date',
            type: CustomFieldType::Date,
            label: 'Release Date',
            itemType: CustomFieldItemType::Product,
            sortOrder: 0,
            allowedValues: null,
        );
    }

    private function createDateTimeDefinition(): CustomFieldDefinition
    {
        return new CustomFieldDefinition(
            id: 2,
            name: 'published_at',
            type: CustomFieldType::DateTime,
            label: 'Published At',
            itemType: CustomFieldItemType::Product,
            sortOrder: 1,
            allowedValues: null,
        );
    }
}
