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
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
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

        self::assertNotNull($settings);
        self::assertSame('Spring clearance', $settings->saleReason);
        self::assertSame('End of season', $settings->saleComments);
        self::assertSame('2026-03-23', $settings->saleStartDate?->format('Y-m-d'));
        self::assertSame('2026-04-01', $settings->saleEndDate?->format('Y-m-d'));
        self::assertSame(5, $settings->saleEndsStock);
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

        self::assertNotNull($settings);
        self::assertNull($settings->saleStartDate);
        self::assertNull($settings->saleEndDate);
        self::assertNull($settings->saleEndsStock);
        self::assertNull($settings->saleComments);
    }

    private function stringField(string $name, string $value): StringCustomFieldValue
    {
        return new StringCustomFieldValue(
            new ConfiguredFieldDefinition(
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
