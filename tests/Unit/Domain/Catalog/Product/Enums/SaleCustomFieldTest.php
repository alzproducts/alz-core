<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Enums;

use App\Domain\Catalog\Product\Enums\SaleCustomField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SaleCustomField::class)]
final class SaleCustomFieldTest extends TestCase
{
    #[Test]
    public function date_start_case_uses_sale_date_start_value(): void
    {
        self::assertSame('sale_date_start', SaleCustomField::DateStart->value);
    }

    #[Test]
    public function date_end_case_uses_sale_date_end_value(): void
    {
        self::assertSame('sale_date_end', SaleCustomField::DateEnd->value);
    }

    #[Test]
    public function reason_case_uses_sale_reason_value(): void
    {
        self::assertSame('sale_reason', SaleCustomField::Reason->value);
    }

    #[Test]
    public function comments_case_uses_sale_comments_value(): void
    {
        self::assertSame('sale_comments', SaleCustomField::Comments->value);
    }

    #[Test]
    public function ends_stock_case_uses_sale_ends_stock_value(): void
    {
        self::assertSame('sale_ends_stock', SaleCustomField::EndsStock->value);
    }

    #[Test]
    public function defines_exactly_five_cases(): void
    {
        self::assertCount(5, SaleCustomField::cases());
    }

    #[Test]
    public function empty_values_returns_all_field_names_mapped_to_empty_strings(): void
    {
        self::assertSame([
            'sale_date_start' => '',
            'sale_date_end' => '',
            'sale_reason' => '',
            'sale_comments' => '',
            'sale_ends_stock' => '',
        ], SaleCustomField::emptyValues());
    }

    #[Test]
    public function empty_values_keys_match_each_case_backing_value(): void
    {
        $values = SaleCustomField::emptyValues();

        self::assertArrayHasKey(SaleCustomField::DateStart->value, $values);
        self::assertArrayHasKey(SaleCustomField::DateEnd->value, $values);
        self::assertArrayHasKey(SaleCustomField::Reason->value, $values);
        self::assertArrayHasKey(SaleCustomField::Comments->value, $values);
        self::assertArrayHasKey(SaleCustomField::EndsStock->value, $values);
    }

    #[Test]
    public function empty_values_has_one_entry_per_case(): void
    {
        self::assertCount(\count(SaleCustomField::cases()), SaleCustomField::emptyValues());
    }

    #[Test]
    public function empty_values_returns_only_empty_strings(): void
    {
        foreach (SaleCustomField::emptyValues() as $value) {
            self::assertSame('', $value);
        }
    }
}
