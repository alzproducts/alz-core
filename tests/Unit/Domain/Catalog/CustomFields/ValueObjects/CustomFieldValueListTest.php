<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldValueList;
use App\Domain\Catalog\CustomFields\ValueObjects\DateTimeCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ToggleCustomFieldValue;
use App\Domain\ValueObjects\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomFieldValueList::class)]
final class CustomFieldValueListTest extends TestCase
{
    // ========================================================================
    // Factories
    // ========================================================================

    #[Test]
    public function empty_creates_list_with_no_fields(): void
    {
        $list = CustomFieldValueList::empty();

        self::assertTrue($list->isEmpty());
        self::assertCount(0, $list);
        self::assertSame([], $list->toList());
    }

    #[Test]
    public function from_preserves_fields_and_order(): void
    {
        $fieldA = $this->stringField('color', 'red');
        $fieldB = $this->stringField('size', 'large');

        $list = CustomFieldValueList::from([$fieldA, $fieldB]);

        self::assertFalse($list->isEmpty());
        self::assertCount(2, $list);
        self::assertSame([$fieldA, $fieldB], $list->toList());
    }

    // ========================================================================
    // findByName
    // ========================================================================

    #[Test]
    public function find_by_name_returns_matching_field(): void
    {
        $fieldA = $this->stringField('color', 'red');
        $fieldB = $this->stringField('size', 'large');

        $list = CustomFieldValueList::from([$fieldA, $fieldB]);

        self::assertSame($fieldB, $list->findByName('size'));
    }

    #[Test]
    public function find_by_name_returns_null_when_absent(): void
    {
        $list = CustomFieldValueList::from([$this->stringField('color', 'red')]);

        self::assertNull($list->findByName('material'));
    }

    #[Test]
    public function find_by_name_returns_first_match_on_duplicates(): void
    {
        $first = $this->stringField('color', 'red');
        $second = $this->stringField('color', 'blue');

        $list = CustomFieldValueList::from([$first, $second]);

        self::assertSame($first, $list->findByName('color'));
    }

    // ========================================================================
    // stringByName
    // ========================================================================

    #[Test]
    public function string_by_name_returns_value_for_string_field(): void
    {
        $list = CustomFieldValueList::from([$this->stringField('color', 'red')]);

        self::assertSame('red', $list->stringByName('color'));
    }

    #[Test]
    public function string_by_name_returns_null_for_empty_string_value(): void
    {
        $list = CustomFieldValueList::from([$this->stringField('color', '')]);

        self::assertNull($list->stringByName('color'));
    }

    #[Test]
    public function string_by_name_returns_null_for_non_string_field(): void
    {
        $list = CustomFieldValueList::from([$this->toggleField('on_sale', true)]);

        self::assertNull($list->stringByName('on_sale'));
    }

    #[Test]
    public function string_by_name_returns_null_when_absent(): void
    {
        self::assertNull(CustomFieldValueList::empty()->stringByName('color'));
    }

    // ========================================================================
    // dateTimeByName
    // ========================================================================

    #[Test]
    public function date_time_by_name_returns_value_for_date_time_field(): void
    {
        $date = new DateTimeImmutable('2026-06-15 10:30:00');
        $list = CustomFieldValueList::from([$this->dateTimeField('preorder_date', $date)]);

        self::assertSame($date, $list->dateTimeByName('preorder_date'));
    }

    #[Test]
    public function date_time_by_name_parses_date_shaped_string_field(): void
    {
        $list = CustomFieldValueList::from([$this->stringField('sale_date_start', '2026-06-15')]);

        $result = $list->dateTimeByName('sale_date_start');

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2026-06-15', $result->format('Y-m-d'));
    }

    #[Test]
    public function date_time_by_name_returns_null_for_unparseable_string(): void
    {
        $list = CustomFieldValueList::from([$this->stringField('sale_date_start', 'not-a-date')]);

        self::assertNull($list->dateTimeByName('sale_date_start'));
    }

    #[Test]
    public function date_time_by_name_returns_null_for_empty_string_value(): void
    {
        $list = CustomFieldValueList::from([$this->stringField('sale_date_start', '')]);

        self::assertNull($list->dateTimeByName('sale_date_start'));
    }

    #[Test]
    public function date_time_by_name_returns_null_for_other_field_types(): void
    {
        $list = CustomFieldValueList::from([$this->toggleField('on_sale', true)]);

        self::assertNull($list->dateTimeByName('on_sale'));
    }

    #[Test]
    public function date_time_by_name_returns_null_when_absent(): void
    {
        self::assertNull(CustomFieldValueList::empty()->dateTimeByName('preorder_date'));
    }

    // ========================================================================
    // withNames
    // ========================================================================

    #[Test]
    public function with_names_returns_subset_preserving_list_order(): void
    {
        $fieldA = $this->stringField('color', 'red');
        $fieldB = $this->stringField('size', 'large');
        $fieldC = $this->stringField('material', 'wood');

        $list = CustomFieldValueList::from([$fieldA, $fieldB, $fieldC]);

        // Names given in reverse — result keeps the list's own order
        $subset = $list->withNames(['material', 'color']);

        self::assertSame([$fieldA, $fieldC], $subset->toList());
    }

    #[Test]
    public function with_names_with_empty_names_returns_full_list(): void
    {
        $fieldA = $this->stringField('color', 'red');

        $list = CustomFieldValueList::from([$fieldA]);

        self::assertSame([$fieldA], $list->withNames([])->toList());
    }

    #[Test]
    public function with_names_with_unknown_names_returns_empty_list(): void
    {
        $list = CustomFieldValueList::from([$this->stringField('color', 'red')]);

        self::assertTrue($list->withNames(['material'])->isEmpty());
    }

    // ========================================================================
    // mapByName
    // ========================================================================

    #[Test]
    public function map_by_name_keys_fields_by_name(): void
    {
        $fieldA = $this->stringField('color', 'red');
        $fieldB = $this->stringField('size', 'large');

        $map = CustomFieldValueList::from([$fieldA, $fieldB])->mapByName();

        self::assertSame(['color' => $fieldA, 'size' => $fieldB], $map);
    }

    #[Test]
    public function map_by_name_last_write_wins_on_duplicates(): void
    {
        $first = $this->stringField('color', 'red');
        $second = $this->stringField('color', 'blue');

        $map = CustomFieldValueList::from([$first, $second])->mapByName();

        self::assertSame(['color' => $second], $map);
    }

    // ========================================================================
    // Iteration + count
    // ========================================================================

    #[Test]
    public function iterates_fields_in_order(): void
    {
        $fieldA = $this->stringField('color', 'red');
        $fieldB = $this->stringField('size', 'large');

        $seen = [];
        foreach (CustomFieldValueList::from([$fieldA, $fieldB]) as $field) {
            $seen[] = $field;
        }

        self::assertSame([$fieldA, $fieldB], $seen);
    }

    #[Test]
    public function count_reflects_field_total(): void
    {
        $list = CustomFieldValueList::from([
            $this->stringField('color', 'red'),
            $this->stringField('size', 'large'),
            $this->toggleField('on_sale', false),
        ]);

        self::assertSame(3, $list->count());
        self::assertCount(3, $list);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function stringField(string $name, string $value): StringCustomFieldValue
    {
        return new StringCustomFieldValue($this->definition($name, CustomFieldType::Text), $value);
    }

    private function toggleField(string $name, bool $value): ToggleCustomFieldValue
    {
        return new ToggleCustomFieldValue($this->definition($name, CustomFieldType::Toggle), $value);
    }

    private function dateTimeField(string $name, DateTimeImmutable $value): DateTimeCustomFieldValue
    {
        return new DateTimeCustomFieldValue($this->definition($name, CustomFieldType::Date), $value);
    }

    private function definition(string $name, CustomFieldType $type): ConfiguredFieldDefinition
    {
        return new ConfiguredFieldDefinition(
            new Uuid('11111111-2222-3333-4444-555555555555'),
            new CustomFieldDefinition(
                id: 1,
                name: $name,
                type: $type,
                label: \ucfirst($name),
                itemType: CustomFieldItemType::Product,
                sortOrder: null,
                allowedValues: null,
            ),
            null,
            null,
        );
    }
}
