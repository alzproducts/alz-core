<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\Enums;

use App\Domain\Inventory\Enums\WeightUnit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WeightUnit Enum Unit Tests.
 *
 * Tests conversion methods that perform math operations
 * which PHPStan cannot verify for correctness.
 */
#[CoversClass(WeightUnit::class)]
final class WeightUnitTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | toGrams() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function kilogram_to_grams_multiplies_by_1000(): void
    {
        $this->assertSame(1000.0, WeightUnit::Kilogram->toGrams(1.0));
        $this->assertSame(2500.0, WeightUnit::Kilogram->toGrams(2.5));
        $this->assertSame(0.0, WeightUnit::Kilogram->toGrams(0.0));
    }

    #[Test]
    public function gram_to_grams_returns_same_value(): void
    {
        $this->assertSame(100.0, WeightUnit::Gram->toGrams(100.0));
        $this->assertSame(0.5, WeightUnit::Gram->toGrams(0.5));
        $this->assertSame(0.0, WeightUnit::Gram->toGrams(0.0));
    }

    /*
    |--------------------------------------------------------------------------
    | toKilograms() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function kilogram_to_kilograms_returns_same_value(): void
    {
        $this->assertSame(1.0, WeightUnit::Kilogram->toKilograms(1.0));
        $this->assertSame(2.5, WeightUnit::Kilogram->toKilograms(2.5));
        $this->assertSame(0.0, WeightUnit::Kilogram->toKilograms(0.0));
    }

    #[Test]
    public function gram_to_kilograms_divides_by_1000(): void
    {
        $this->assertSame(0.001, WeightUnit::Gram->toKilograms(1.0));
        $this->assertSame(2.5, WeightUnit::Gram->toKilograms(2500.0));
        $this->assertSame(0.0, WeightUnit::Gram->toKilograms(0.0));
    }

    /*
    |--------------------------------------------------------------------------
    | Edge Cases
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('precisionDataProvider')]
    public function conversions_maintain_precision(WeightUnit $unit, float $input, float $expectedGrams, float $expectedKg): void
    {
        $this->assertSame($expectedGrams, $unit->toGrams($input));
        $this->assertSame($expectedKg, $unit->toKilograms($input));
    }

    /**
     * @return array<string, array{WeightUnit, float, float, float}>
     */
    public static function precisionDataProvider(): array
    {
        return [
            'kg small decimal' => [WeightUnit::Kilogram, 0.001, 1.0, 0.001],
            'kg large value' => [WeightUnit::Kilogram, 1000.0, 1000000.0, 1000.0],
            'gram small decimal' => [WeightUnit::Gram, 0.5, 0.5, 0.0005],
            'gram large value' => [WeightUnit::Gram, 10000.0, 10000.0, 10.0],
        ];
    }
}
