<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\ValueObjects\WeightUnit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WeightUnit Enum Unit Tests.
 *
 * Tests the WeightUnit domain enum including conversion methods.
 */
#[CoversClass(WeightUnit::class)]
final class WeightUnitTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Enum Value Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function kilograms_has_correct_value(): void
    {
        $this->assertSame('kg', WeightUnit::Kilograms->value);
    }

    /*
    |--------------------------------------------------------------------------
    | toGrams() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('kilogramsToGramsProvider')]
    public function to_grams_converts_kilograms_correctly(float $kilograms, float $expectedGrams): void
    {
        $result = WeightUnit::Kilograms->toGrams($kilograms);

        $this->assertSame($expectedGrams, $result);
    }

    /**
     * @return array<string, array{float, float}>
     */
    public static function kilogramsToGramsProvider(): array
    {
        return [
            'zero kilograms' => [0.0, 0.0],
            'one kilogram' => [1.0, 1000.0],
            'fractional kilograms' => [0.5, 500.0],
            'large weight' => [10.0, 10000.0],
            'precise weight' => [1.234, 1234.0],
            'small weight' => [0.001, 1.0],
        ];
    }
}
