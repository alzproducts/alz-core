<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\Enums;

use App\Domain\Inventory\Enums\SkuUpdateReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkuUpdateReason::class)]
final class SkuUpdateReasonTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Enum Case Instantiation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('allCasesProvider')]
    public function enum_case_has_correct_backing_value(SkuUpdateReason $case, string $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * @return array<string, array{SkuUpdateReason, string}>
     */
    public static function allCasesProvider(): array
    {
        return [
            'shorten long sku' => [SkuUpdateReason::ShortenLongSku, 'shorten_long_sku'],
            'fix sku mismatch' => [SkuUpdateReason::FixSkuMismatch, 'fix_sku_mismatch'],
            'standardize format' => [SkuUpdateReason::StandardizeFormat, 'standardize_format'],
            'merge products' => [SkuUpdateReason::MergeProducts, 'merge_products'],
            'other' => [SkuUpdateReason::Other, 'other'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | label() Method Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('labelProvider')]
    public function label_returns_human_readable_text(SkuUpdateReason $reason, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $reason->label());
    }

    /**
     * @return array<string, array{SkuUpdateReason, string}>
     */
    public static function labelProvider(): array
    {
        return [
            'shorten long sku' => [SkuUpdateReason::ShortenLongSku, 'Shorten long SKU'],
            'fix sku mismatch' => [SkuUpdateReason::FixSkuMismatch, 'Fix SKU mismatch'],
            'standardize format' => [SkuUpdateReason::StandardizeFormat, 'Standardize format'],
            'merge products' => [SkuUpdateReason::MergeProducts, 'Merge products'],
            'other' => [SkuUpdateReason::Other, 'Other'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Enum From Value Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('fromValueProvider')]
    public function enum_can_be_created_from_string_value(string $value, SkuUpdateReason $expected): void
    {
        self::assertSame($expected, SkuUpdateReason::from($value));
    }

    /**
     * @return array<string, array{string, SkuUpdateReason}>
     */
    public static function fromValueProvider(): array
    {
        return [
            'shorten_long_sku string' => ['shorten_long_sku', SkuUpdateReason::ShortenLongSku],
            'fix_sku_mismatch string' => ['fix_sku_mismatch', SkuUpdateReason::FixSkuMismatch],
            'standardize_format string' => ['standardize_format', SkuUpdateReason::StandardizeFormat],
            'merge_products string' => ['merge_products', SkuUpdateReason::MergeProducts],
            'other string' => ['other', SkuUpdateReason::Other],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | All Cases Enumeration Test
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function cases_returns_all_five_reasons(): void
    {
        $cases = SkuUpdateReason::cases();

        self::assertCount(5, $cases);
        self::assertContains(SkuUpdateReason::ShortenLongSku, $cases);
        self::assertContains(SkuUpdateReason::FixSkuMismatch, $cases);
        self::assertContains(SkuUpdateReason::StandardizeFormat, $cases);
        self::assertContains(SkuUpdateReason::MergeProducts, $cases);
        self::assertContains(SkuUpdateReason::Other, $cases);
    }
}
