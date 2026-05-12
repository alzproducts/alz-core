<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\DateTimeCustomFieldValue;
use App\Domain\ValueObjects\Uuid;
use DateTimeImmutable;
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
        $utcDateTime = new DateTimeImmutable('2024-06-15 12:00:00', new DateTimeZone('UTC'));
        $timestamp = $utcDateTime->getTimestamp();

        $value = DateTimeCustomFieldValue::fromTimestamp($definition, $timestamp);

        self::assertSame('Europe/London', $value->value->getTimezone()->getName());
        self::assertSame('2024-06-15 13:00:00', $value->value->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function from_timestamp_handles_winter_time(): void
    {
        $definition = $this->createDateTimeDefinition();
        $utcDateTime = new DateTimeImmutable('2024-01-15 12:00:00', new DateTimeZone('UTC'));
        $timestamp = $utcDateTime->getTimestamp();

        $value = DateTimeCustomFieldValue::fromTimestamp($definition, $timestamp);

        self::assertSame('Europe/London', $value->value->getTimezone()->getName());
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

        self::assertSame('1970-01-01 01:00:00', $value->value->format('Y-m-d H:i:s'));
    }

    // ========================================================================
    // Happy Path - fromDateString Factory
    // ========================================================================

    #[Test]
    public function from_date_string_parses_date_only(): void
    {
        $definition = $this->createDateDefinition();

        $value = DateTimeCustomFieldValue::fromDateString($definition, '2026-06-15');

        self::assertSame('2026-06-15', $value->value->format('Y-m-d'));
        self::assertSame('Europe/London', $value->value->getTimezone()->getName());
    }

    #[Test]
    public function from_date_string_parses_datetime_with_time(): void
    {
        $definition = $this->createDateTimeDefinition();

        $value = DateTimeCustomFieldValue::fromDateString($definition, '2026-06-15T14:30:00');

        self::assertSame('2026-06-15 14:30:00', $value->value->format('Y-m-d H:i:s'));
        self::assertSame('Europe/London', $value->value->getTimezone()->getName());
    }

    #[Test]
    public function from_date_string_parses_iso8601_with_timezone(): void
    {
        $definition = $this->createDateTimeDefinition();

        $value = DateTimeCustomFieldValue::fromDateString($definition, '2026-06-15T14:30:00+02:00');

        self::assertSame('2026-06-15 13:30:00', $value->value->format('Y-m-d H:i:s'));
        self::assertSame('Europe/London', $value->value->getTimezone()->getName());
    }

    #[Test]
    public function from_date_string_throws_on_invalid_string(): void
    {
        $definition = $this->createDateDefinition();

        try {
            DateTimeCustomFieldValue::fromDateString($definition, 'not-a-date');
            self::fail('Expected InvalidCustomFieldValueException');
        } catch (InvalidCustomFieldValueException $e) {
            self::assertSame('release_date', $e->fieldName);
            self::assertSame(CustomFieldType::Date, $e->expectedType);
            self::assertSame('string (invalid date)', $e->actualType);
            self::assertSame('not-a-date', $e->rawValue);
        }
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
    // Typed accessors
    // ========================================================================

    #[Test]
    public function it_preserves_datetime_immutable_value_and_timestamp(): void
    {
        $definition = $this->createDateTimeDefinition();
        $dateTime = new DateTimeImmutable('2024-06-15T14:30:00+00:00');
        $value = new DateTimeCustomFieldValue($definition, $dateTime);

        self::assertInstanceOf(DateTimeImmutable::class, $value->value);
        self::assertSame($dateTime->getTimestamp(), $value->value->getTimestamp());
    }

    #[Test]
    public function it_exposes_typed_metadata_accessors(): void
    {
        $definition = $this->createDateTimeDefinition();
        $dateTime = new DateTimeImmutable('2024-06-15T14:30:00+00:00');
        $value = new DateTimeCustomFieldValue($definition, $dateTime);

        self::assertSame('published_at', $value->name());
        self::assertSame(CustomFieldType::DateTime, $value->type());
        self::assertSame('Published At', $value->label());
        self::assertNull($value->definition->base->allowedValues);
        self::assertSame(1, $value->definition->base->sortOrder);
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
        $this->expectExceptionMessage("DateTimeCustomFieldValue requires date type (Date/DateTime), got 'text'");

        new DateTimeCustomFieldValue($definition, new DateTimeImmutable());
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
        $this->expectExceptionMessage("DateTimeCustomFieldValue requires date type (Date/DateTime), got 'toggle'");

        new DateTimeCustomFieldValue($definition, new DateTimeImmutable());
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
        $this->expectExceptionMessage("DateTimeCustomFieldValue requires date type (Date/DateTime), got 'choice'");

        new DateTimeCustomFieldValue($definition, new DateTimeImmutable());
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
        $this->expectExceptionMessage("DateTimeCustomFieldValue requires date type (Date/DateTime), got 'value_list'");

        new DateTimeCustomFieldValue($definition, new DateTimeImmutable());
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
        $this->expectExceptionMessage("DateTimeCustomFieldValue requires date type (Date/DateTime), got 'product_list'");

        new DateTimeCustomFieldValue($definition, new DateTimeImmutable());
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createDateDefinition(): ConfiguredFieldDefinition
    {
        return self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'release_date',
            type: CustomFieldType::Date,
            label: 'Release Date',
            itemType: CustomFieldItemType::Product,
            sortOrder: 0,
            allowedValues: null,
        ));
    }

    private function createDateTimeDefinition(): ConfiguredFieldDefinition
    {
        return self::wrap(new CustomFieldDefinition(
            id: 2,
            name: 'published_at',
            type: CustomFieldType::DateTime,
            label: 'Published At',
            itemType: CustomFieldItemType::Product,
            sortOrder: 1,
            allowedValues: null,
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
