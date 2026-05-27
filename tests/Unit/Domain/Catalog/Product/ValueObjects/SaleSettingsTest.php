<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\DateTimeCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use App\Domain\Catalog\Product\Enums\SaleCustomField;
use App\Domain\Catalog\Product\Enums\SaleRemovalReason;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\ValueObjects\Uuid;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SaleSettings::class)]
final class SaleSettingsTest extends TestCase
{
    #[Test]
    public function constructor_sets_all_properties(): void
    {
        $startDate = new DateTimeImmutable('2026-03-23');
        $endDate = new DateTimeImmutable('2026-04-01');

        $settings = new SaleSettings(
            saleReason: 'Spring clearance',
            saleComments: 'End of season',
            saleStartDate: $startDate,
            saleEndDate: $endDate,
            saleEndsStock: 5,
            removalReason: SaleRemovalReason::Manual,
        );

        self::assertSame('Spring clearance', $settings->saleReason);
        self::assertSame('End of season', $settings->saleComments);
        self::assertSame($startDate, $settings->saleStartDate);
        self::assertSame($endDate, $settings->saleEndDate);
        self::assertSame(5, $settings->saleEndsStock);
        self::assertSame(SaleRemovalReason::Manual, $settings->removalReason);
    }

    #[Test]
    public function constructor_defaults_optional_fields_to_null(): void
    {
        $settings = new SaleSettings(saleReason: 'Flash sale');

        self::assertSame('Flash sale', $settings->saleReason);
        self::assertNull($settings->saleComments);
        self::assertNull($settings->saleStartDate);
        self::assertNull($settings->saleEndDate);
        self::assertNull($settings->saleEndsStock);
        self::assertNull($settings->removalReason);
    }

    #[Test]
    public function for_removal_creates_settings_with_reason_label(): void
    {
        $settings = SaleSettings::forRemoval(SaleRemovalReason::EndDateReached);

        self::assertSame('Sale end date reached', $settings->saleReason);
        self::assertSame(SaleRemovalReason::EndDateReached, $settings->removalReason);
    }

    #[Test]
    public function for_removal_leaves_optional_fields_null(): void
    {
        $settings = SaleSettings::forRemoval(SaleRemovalReason::ProductInactive);

        self::assertNull($settings->saleComments);
        self::assertNull($settings->saleStartDate);
        self::assertNull($settings->saleEndDate);
        self::assertNull($settings->saleEndsStock);
    }

    #[Test]
    public function for_removal_uses_correct_label_for_each_reason(): void
    {
        foreach (SaleRemovalReason::cases() as $reason) {
            $settings = SaleSettings::forRemoval($reason);

            self::assertSame($reason->label(), $settings->saleReason);
            self::assertSame($reason, $settings->removalReason);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | fromTypedCustomFields
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_typed_custom_fields_returns_null_when_reason_missing(): void
    {
        $fields = [
            $this->stringField(SaleCustomField::Comments->value, 'End of season'),
        ];

        self::assertNull(SaleSettings::fromTypedCustomFields($fields));
    }

    #[Test]
    public function from_typed_custom_fields_returns_null_when_reason_empty(): void
    {
        $fields = [
            $this->stringField(SaleCustomField::Reason->value, ''),
            $this->stringField(SaleCustomField::Comments->value, 'End of season'),
        ];

        self::assertNull(SaleSettings::fromTypedCustomFields($fields));
    }

    #[Test]
    public function from_typed_custom_fields_parses_all_string_fields(): void
    {
        $fields = [
            $this->stringField(SaleCustomField::Reason->value, 'Spring clearance'),
            $this->stringField(SaleCustomField::Comments->value, 'End of season'),
            $this->stringField(SaleCustomField::DateStart->value, '2026-03-23'),
            $this->stringField(SaleCustomField::DateEnd->value, '2026-04-01'),
            $this->stringField(SaleCustomField::EndsStock->value, '5'),
        ];

        $settings = SaleSettings::fromTypedCustomFields($fields);

        self::assertInstanceOf(SaleSettings::class, $settings);
        self::assertSame('Spring clearance', $settings->saleReason);
        self::assertSame('End of season', $settings->saleComments);
        self::assertSame('2026-03-23', $settings->saleStartDate?->format('Y-m-d'));
        self::assertSame('2026-04-01', $settings->saleEndDate?->format('Y-m-d'));
        self::assertSame(5, $settings->saleEndsStock);
        self::assertNull($settings->removalReason);
    }

    #[Test]
    public function from_typed_custom_fields_reads_date_fields_directly(): void
    {
        $start = new DateTimeImmutable('2026-03-23');
        $end = new DateTimeImmutable('2026-04-01');
        $fields = [
            $this->stringField(SaleCustomField::Reason->value, 'Spring clearance'),
            $this->dateField(SaleCustomField::DateStart->value, $start),
            $this->dateField(SaleCustomField::DateEnd->value, $end),
        ];

        $settings = SaleSettings::fromTypedCustomFields($fields);

        self::assertNotNull($settings);
        self::assertSame($start, $settings->saleStartDate);
        self::assertSame($end, $settings->saleEndDate);
    }

    #[Test]
    public function from_typed_custom_fields_nulls_empty_comments_and_non_numeric_stock(): void
    {
        $fields = [
            $this->stringField(SaleCustomField::Reason->value, 'Spring clearance'),
            $this->stringField(SaleCustomField::Comments->value, ''),
            $this->stringField(SaleCustomField::EndsStock->value, 'not-a-number'),
        ];

        $settings = SaleSettings::fromTypedCustomFields($fields);

        self::assertNotNull($settings);
        self::assertNull($settings->saleComments);
        self::assertNull($settings->saleEndsStock);
    }

    #[Test]
    public function from_typed_custom_fields_returns_null_dates_when_fields_missing(): void
    {
        $fields = [
            $this->stringField(SaleCustomField::Reason->value, 'Spring clearance'),
        ];

        $settings = SaleSettings::fromTypedCustomFields($fields);

        self::assertInstanceOf(SaleSettings::class, $settings);
        self::assertNull($settings->saleStartDate);
        self::assertNull($settings->saleEndDate);
        self::assertNull($settings->saleEndsStock);
        self::assertNull($settings->saleComments);
    }

    /*
    |--------------------------------------------------------------------------
    | fromRawCustomFields
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_raw_custom_fields_returns_null_when_reason_missing(): void
    {
        self::assertNull(SaleSettings::fromRawCustomFields([
            SaleCustomField::Comments->value => 'End of season',
        ]));
    }

    #[Test]
    public function from_raw_custom_fields_returns_null_when_reason_empty(): void
    {
        self::assertNull(SaleSettings::fromRawCustomFields([
            SaleCustomField::Reason->value => '',
            SaleCustomField::Comments->value => 'End of season',
        ]));
    }

    #[Test]
    public function from_raw_custom_fields_returns_null_when_reason_not_string(): void
    {
        self::assertNull(SaleSettings::fromRawCustomFields([
            SaleCustomField::Reason->value => 42,
        ]));
    }

    #[Test]
    public function from_raw_custom_fields_parses_full_payload(): void
    {
        $settings = SaleSettings::fromRawCustomFields([
            SaleCustomField::Reason->value => 'Spring clearance',
            SaleCustomField::Comments->value => 'End of season',
            SaleCustomField::DateStart->value => '2026-03-23',
            SaleCustomField::DateEnd->value => '2026-04-01',
            SaleCustomField::EndsStock->value => '5',
        ]);

        self::assertInstanceOf(SaleSettings::class, $settings);
        self::assertSame('Spring clearance', $settings->saleReason);
        self::assertSame('End of season', $settings->saleComments);
        self::assertSame('2026-03-23', $settings->saleStartDate?->format('Y-m-d'));
        self::assertSame('2026-04-01', $settings->saleEndDate?->format('Y-m-d'));
        self::assertSame(5, $settings->saleEndsStock);
        self::assertNull($settings->removalReason);
    }

    #[Test]
    public function from_raw_custom_fields_nulls_empty_comments(): void
    {
        $settings = SaleSettings::fromRawCustomFields([
            SaleCustomField::Reason->value => 'Spring clearance',
            SaleCustomField::Comments->value => '',
        ]);

        self::assertInstanceOf(SaleSettings::class, $settings);
        self::assertNull($settings->saleComments);
    }

    #[Test]
    public function from_raw_custom_fields_nulls_non_string_comments(): void
    {
        $settings = SaleSettings::fromRawCustomFields([
            SaleCustomField::Reason->value => 'Spring clearance',
            SaleCustomField::Comments->value => ['unexpected'],
        ]);

        self::assertInstanceOf(SaleSettings::class, $settings);
        self::assertNull($settings->saleComments);
    }

    #[Test]
    public function from_raw_custom_fields_nulls_non_numeric_ends_stock(): void
    {
        $settings = SaleSettings::fromRawCustomFields([
            SaleCustomField::Reason->value => 'Spring clearance',
            SaleCustomField::EndsStock->value => 'not-a-number',
        ]);

        self::assertInstanceOf(SaleSettings::class, $settings);
        self::assertNull($settings->saleEndsStock);
    }

    #[Test]
    public function from_raw_custom_fields_nulls_empty_ends_stock(): void
    {
        $settings = SaleSettings::fromRawCustomFields([
            SaleCustomField::Reason->value => 'Spring clearance',
            SaleCustomField::EndsStock->value => '',
        ]);

        self::assertInstanceOf(SaleSettings::class, $settings);
        self::assertNull($settings->saleEndsStock);
    }

    #[Test]
    public function from_raw_custom_fields_nulls_non_string_ends_stock(): void
    {
        $settings = SaleSettings::fromRawCustomFields([
            SaleCustomField::Reason->value => 'Spring clearance',
            SaleCustomField::EndsStock->value => 5,
        ]);

        self::assertInstanceOf(SaleSettings::class, $settings);
        self::assertNull($settings->saleEndsStock);
    }

    #[Test]
    public function from_raw_custom_fields_truncates_decimal_stock_to_int(): void
    {
        $settings = SaleSettings::fromRawCustomFields([
            SaleCustomField::Reason->value => 'Spring clearance',
            SaleCustomField::EndsStock->value => '5.9',
        ]);

        self::assertInstanceOf(SaleSettings::class, $settings);
        self::assertSame(5, $settings->saleEndsStock);
    }

    #[Test]
    #[DataProvider('invalidDateProvider')]
    public function from_raw_custom_fields_returns_null_date_for_invalid_string(mixed $invalidDate): void
    {
        $settings = SaleSettings::fromRawCustomFields([
            SaleCustomField::Reason->value => 'Spring clearance',
            SaleCustomField::DateStart->value => $invalidDate,
        ]);

        self::assertInstanceOf(SaleSettings::class, $settings);
        self::assertNull($settings->saleStartDate);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidDateProvider(): array
    {
        return [
            'empty string' => [''],
            'non-string (int)' => [123],
            'non-string (array)' => [['2026-03-23']],
            'unparseable' => ['not-a-date'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | fromTypedCustomFields — extra branches
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_typed_custom_fields_parses_iso_date_string_in_string_field(): void
    {
        $fields = [
            $this->stringField(SaleCustomField::Reason->value, 'Spring clearance'),
            $this->stringField(SaleCustomField::DateStart->value, '2026-03-23'),
        ];

        $settings = SaleSettings::fromTypedCustomFields($fields);

        self::assertInstanceOf(SaleSettings::class, $settings);
        self::assertSame('2026-03-23', $settings->saleStartDate?->format('Y-m-d'));
    }

    #[Test]
    public function from_typed_custom_fields_returns_null_date_for_unparseable_string(): void
    {
        $fields = [
            $this->stringField(SaleCustomField::Reason->value, 'Spring clearance'),
            $this->stringField(SaleCustomField::DateStart->value, 'not-a-date'),
        ];

        $settings = SaleSettings::fromTypedCustomFields($fields);

        self::assertInstanceOf(SaleSettings::class, $settings);
        self::assertNull($settings->saleStartDate);
    }

    #[Test]
    public function from_typed_custom_fields_parses_numeric_stock_string(): void
    {
        $fields = [
            $this->stringField(SaleCustomField::Reason->value, 'Spring clearance'),
            $this->stringField(SaleCustomField::EndsStock->value, '12'),
        ];

        $settings = SaleSettings::fromTypedCustomFields($fields);

        self::assertInstanceOf(SaleSettings::class, $settings);
        self::assertSame(12, $settings->saleEndsStock);
    }

    /*
    |--------------------------------------------------------------------------
    | toCustomFieldsArray
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_custom_fields_array_emits_all_settings_fields(): void
    {
        $settings = new SaleSettings(
            saleReason: 'Spring clearance',
            saleComments: 'End of season',
            saleStartDate: new DateTimeImmutable('2026-03-23'),
            saleEndDate: new DateTimeImmutable('2026-04-01'),
            saleEndsStock: 5,
        );

        $array = SaleSettings::toCustomFieldsArray($settings);

        self::assertSame('2026-03-23', $array[SaleCustomField::DateStart->value]);
        self::assertSame('Spring clearance', $array[SaleCustomField::Reason->value]);
        self::assertSame('End of season', $array[SaleCustomField::Comments->value]);
        self::assertSame('2026-04-01', $array[SaleCustomField::DateEnd->value]);
        self::assertSame('5', $array[SaleCustomField::EndsStock->value]);
    }

    #[Test]
    public function to_custom_fields_array_emits_empty_strings_for_null_optional_fields(): void
    {
        $settings = new SaleSettings(
            saleReason: 'Flash sale',
            saleStartDate: new DateTimeImmutable('2026-03-23'),
        );

        self::assertSame([
            SaleCustomField::DateStart->value => '2026-03-23',
            SaleCustomField::Reason->value => 'Flash sale',
            SaleCustomField::Comments->value => '',
            SaleCustomField::DateEnd->value => '',
            SaleCustomField::EndsStock->value => '',
        ], SaleSettings::toCustomFieldsArray($settings));
    }

    #[Test]
    public function to_custom_fields_array_handles_null_settings_with_today_start_date(): void
    {
        $today = (new DateTimeImmutable())->format('Y-m-d');

        $array = SaleSettings::toCustomFieldsArray(null);

        self::assertSame($today, $array[SaleCustomField::DateStart->value]);
        self::assertSame('', $array[SaleCustomField::Reason->value]);
        self::assertSame('', $array[SaleCustomField::Comments->value]);
        self::assertSame('', $array[SaleCustomField::DateEnd->value]);
        self::assertSame('', $array[SaleCustomField::EndsStock->value]);
    }

    #[Test]
    public function to_custom_fields_array_stringifies_stock(): void
    {
        $settings = new SaleSettings(
            saleReason: 'Stock-only sale',
            saleStartDate: new DateTimeImmutable('2026-03-23'),
            saleEndsStock: 0,
        );

        $array = SaleSettings::toCustomFieldsArray($settings);

        self::assertSame('0', $array[SaleCustomField::EndsStock->value]);
    }

    /*
    |--------------------------------------------------------------------------
    | toArray
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_array_emits_full_payload_in_atom_format(): void
    {
        $start = new DateTimeImmutable('2026-03-23T10:00:00+00:00');
        $end = new DateTimeImmutable('2026-04-01T18:00:00+00:00');

        $settings = new SaleSettings(
            saleReason: 'Spring clearance',
            saleComments: 'End of season',
            saleStartDate: $start,
            saleEndDate: $end,
            saleEndsStock: 5,
            removalReason: SaleRemovalReason::Manual,
        );

        self::assertSame([
            'sale_reason' => 'Spring clearance',
            'sale_comments' => 'End of season',
            'sale_start_date' => $start->format(DateTimeInterface::ATOM),
            'sale_end_date' => $end->format(DateTimeInterface::ATOM),
            'sale_ends_stock' => 5,
            'removal_reason' => 'manual',
        ], $settings->toArray());
    }

    #[Test]
    public function to_array_emits_nulls_for_optional_fields(): void
    {
        $settings = new SaleSettings(saleReason: 'Flash sale');

        self::assertSame([
            'sale_reason' => 'Flash sale',
            'sale_comments' => null,
            'sale_start_date' => null,
            'sale_end_date' => null,
            'sale_ends_stock' => null,
            'removal_reason' => null,
        ], $settings->toArray());
    }

    private function stringField(string $name, string $value): StringCustomFieldValue
    {
        return new StringCustomFieldValue(
            new ConfiguredFieldDefinition(
                new Uuid('11111111-2222-3333-4444-555555555555'),
                new CustomFieldDefinition(
                    id: 1,
                    name: $name,
                    type: CustomFieldType::Text,
                    label: null,
                    itemType: CustomFieldItemType::Product,
                    sortOrder: null,
                    allowedValues: null,
                ),
                null,
                null,
            ),
            $value,
        );
    }

    private function dateField(string $name, DateTimeImmutable $value): DateTimeCustomFieldValue
    {
        return new DateTimeCustomFieldValue(
            new ConfiguredFieldDefinition(
                new Uuid('11111111-2222-3333-4444-555555555555'),
                new CustomFieldDefinition(
                    id: 1,
                    name: $name,
                    type: CustomFieldType::Date,
                    label: null,
                    itemType: CustomFieldItemType::Product,
                    sortOrder: null,
                    allowedValues: null,
                ),
                null,
                null,
            ),
            $value,
        );
    }
}
