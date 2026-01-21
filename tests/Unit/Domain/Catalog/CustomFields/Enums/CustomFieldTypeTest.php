<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\Enums;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomFieldType::class)]
final class CustomFieldTypeTest extends TestCase
{
    #[Test]
    public function it_has_all_expected_cases(): void
    {
        $cases = CustomFieldType::cases();

        self::assertCount(8, $cases);
        self::assertContains(CustomFieldType::Text, $cases);
        self::assertContains(CustomFieldType::Toggle, $cases);
        self::assertContains(CustomFieldType::Choice, $cases);
        self::assertContains(CustomFieldType::List, $cases);
        self::assertContains(CustomFieldType::Date, $cases);
        self::assertContains(CustomFieldType::DateTime, $cases);
        self::assertContains(CustomFieldType::ValueList, $cases);
        self::assertContains(CustomFieldType::ProductList, $cases);
    }

    #[Test]
    #[DataProvider('requiresAllowedValuesProvider')]
    public function requires_allowed_values_returns_correct_result(CustomFieldType $type, bool $expected): void
    {
        self::assertSame($expected, $type->requiresAllowedValues());
    }

    /**
     * @return array<string, array{CustomFieldType, bool}>
     */
    public static function requiresAllowedValuesProvider(): array
    {
        return [
            'Text does not require' => [CustomFieldType::Text, false],
            'Toggle does not require' => [CustomFieldType::Toggle, false],
            'Choice requires' => [CustomFieldType::Choice, true],
            'List requires' => [CustomFieldType::List, true],
            'Date does not require' => [CustomFieldType::Date, false],
            'DateTime does not require' => [CustomFieldType::DateTime, false],
            'ValueList does not require' => [CustomFieldType::ValueList, false],
            'ProductList does not require' => [CustomFieldType::ProductList, false],
        ];
    }

    #[Test]
    #[DataProvider('isArrayTypeProvider')]
    public function is_array_type_returns_correct_result(CustomFieldType $type, bool $expected): void
    {
        self::assertSame($expected, $type->isArrayType());
    }

    /**
     * @return array<string, array{CustomFieldType, bool}>
     */
    public static function isArrayTypeProvider(): array
    {
        return [
            'Text is not array' => [CustomFieldType::Text, false],
            'Toggle is not array' => [CustomFieldType::Toggle, false],
            'Choice is not array' => [CustomFieldType::Choice, false],
            'List is not array' => [CustomFieldType::List, false],
            'Date is not array' => [CustomFieldType::Date, false],
            'DateTime is not array' => [CustomFieldType::DateTime, false],
            'ValueList is array' => [CustomFieldType::ValueList, true],
            'ProductList is array' => [CustomFieldType::ProductList, true],
        ];
    }

    #[Test]
    #[DataProvider('isStringTypeProvider')]
    public function is_string_type_returns_correct_result(CustomFieldType $type, bool $expected): void
    {
        self::assertSame($expected, $type->isStringType());
    }

    /**
     * @return array<string, array{CustomFieldType, bool}>
     */
    public static function isStringTypeProvider(): array
    {
        return [
            'Text is string' => [CustomFieldType::Text, true],
            'Toggle is not string' => [CustomFieldType::Toggle, false],
            'Choice is string' => [CustomFieldType::Choice, true],
            'List is string' => [CustomFieldType::List, true],
            'Date is not string' => [CustomFieldType::Date, false],
            'DateTime is not string' => [CustomFieldType::DateTime, false],
            'ValueList is not string' => [CustomFieldType::ValueList, false],
            'ProductList is not string' => [CustomFieldType::ProductList, false],
        ];
    }

    #[Test]
    #[DataProvider('isBooleanTypeProvider')]
    public function is_boolean_type_returns_correct_result(CustomFieldType $type, bool $expected): void
    {
        self::assertSame($expected, $type->isBooleanType());
    }

    /**
     * @return array<string, array{CustomFieldType, bool}>
     */
    public static function isBooleanTypeProvider(): array
    {
        return [
            'Text is not boolean' => [CustomFieldType::Text, false],
            'Toggle is boolean' => [CustomFieldType::Toggle, true],
            'Choice is not boolean' => [CustomFieldType::Choice, false],
            'List is not boolean' => [CustomFieldType::List, false],
            'Date is not boolean' => [CustomFieldType::Date, false],
            'DateTime is not boolean' => [CustomFieldType::DateTime, false],
            'ValueList is not boolean' => [CustomFieldType::ValueList, false],
            'ProductList is not boolean' => [CustomFieldType::ProductList, false],
        ];
    }

    #[Test]
    #[DataProvider('isDateTypeProvider')]
    public function is_date_type_returns_correct_result(CustomFieldType $type, bool $expected): void
    {
        self::assertSame($expected, $type->isDateType());
    }

    /**
     * @return array<string, array{CustomFieldType, bool}>
     */
    public static function isDateTypeProvider(): array
    {
        return [
            'Text is not date' => [CustomFieldType::Text, false],
            'Toggle is not date' => [CustomFieldType::Toggle, false],
            'Choice is not date' => [CustomFieldType::Choice, false],
            'List is not date' => [CustomFieldType::List, false],
            'Date is date' => [CustomFieldType::Date, true],
            'DateTime is date' => [CustomFieldType::DateTime, true],
            'ValueList is not date' => [CustomFieldType::ValueList, false],
            'ProductList is not date' => [CustomFieldType::ProductList, false],
        ];
    }
}
