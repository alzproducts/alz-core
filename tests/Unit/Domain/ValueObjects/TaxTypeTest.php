<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ValueObjects;

use App\Domain\ValueObjects\TaxType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaxType::class)]
final class TaxTypeTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Enum Case Instantiation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('allCasesProvider')]
    public function enum_case_can_be_instantiated(TaxType $case, string $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * @return array<string, array{TaxType, string}>
     */
    public static function allCasesProvider(): array
    {
        return [
            'inclusive' => [TaxType::Inclusive, 'inclusive'],
            'exclusive' => [TaxType::Exclusive, 'exclusive'],
            'zero_rated' => [TaxType::ZeroRated, 'zero_rated'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | hasTax() Method Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_tax_returns_true_for_inclusive(): void
    {
        self::assertTrue(TaxType::Inclusive->hasTax());
    }

    #[Test]
    public function has_tax_returns_true_for_exclusive(): void
    {
        self::assertTrue(TaxType::Exclusive->hasTax());
    }

    #[Test]
    public function has_tax_returns_false_for_zero_rated(): void
    {
        self::assertFalse(TaxType::ZeroRated->hasTax());
    }

    /*
    |--------------------------------------------------------------------------
    | Enum From Value Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('fromValueProvider')]
    public function enum_can_be_created_from_string_value(string $value, TaxType $expected): void
    {
        self::assertSame($expected, TaxType::from($value));
    }

    /**
     * @return array<string, array{string, TaxType}>
     */
    public static function fromValueProvider(): array
    {
        return [
            'inclusive string' => ['inclusive', TaxType::Inclusive],
            'exclusive string' => ['exclusive', TaxType::Exclusive],
            'zero_rated string' => ['zero_rated', TaxType::ZeroRated],
        ];
    }
}
