<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\ValueObjects;

use App\Domain\Inventory\Enums\WeightUnit;
use App\Domain\Inventory\ValueObjects\Weight;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Weight Value Object Unit Tests.
 *
 * Tests weight conversions, validation, and factory methods.
 */
#[CoversClass(Weight::class)]
final class WeightTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_weight_with_value_and_unit(): void
    {
        $weight = new Weight(2.5, WeightUnit::Kilogram);

        $this->assertSame(2.5, $weight->value);
        $this->assertSame(WeightUnit::Kilogram, $weight->unit);
    }

    #[Test]
    public function it_throws_for_negative_weight(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Weight cannot be negative');

        new Weight(-1.0, WeightUnit::Kilogram);
    }

    /*
    |--------------------------------------------------------------------------
    | Factory Method Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function zero_creates_zero_weight_with_default_unit(): void
    {
        $weight = Weight::zero();

        $this->assertSame(0.0, $weight->value);
        $this->assertSame(WeightUnit::Kilogram, $weight->unit);
    }

    #[Test]
    public function zero_accepts_custom_unit(): void
    {
        $weight = Weight::zero(WeightUnit::Gram);

        $this->assertSame(0.0, $weight->value);
        $this->assertSame(WeightUnit::Gram, $weight->unit);
    }

    #[Test]
    public function kilogram_factory_creates_kg_weight(): void
    {
        $weight = Weight::kilogram(5.5);

        $this->assertSame(5.5, $weight->value);
        $this->assertSame(WeightUnit::Kilogram, $weight->unit);
    }

    #[Test]
    public function gram_factory_creates_gram_weight(): void
    {
        $weight = Weight::gram(500.0);

        $this->assertSame(500.0, $weight->value);
        $this->assertSame(WeightUnit::Gram, $weight->unit);
    }

    /*
    |--------------------------------------------------------------------------
    | isEmpty Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function isEmpty_returns_true_for_zero_weight(): void
    {
        $this->assertTrue(Weight::zero()->isEmpty());
        $this->assertTrue(Weight::kilogram(0.0)->isEmpty());
        $this->assertTrue(Weight::gram(0.0)->isEmpty());
    }

    #[Test]
    public function isEmpty_returns_false_for_non_zero_weight(): void
    {
        $this->assertFalse(Weight::kilogram(0.001)->isEmpty());
        $this->assertFalse(Weight::gram(1.0)->isEmpty());
    }

    /*
    |--------------------------------------------------------------------------
    | Conversion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function inGrams_converts_kg_to_grams(): void
    {
        $weight = Weight::kilogram(2.5);

        $this->assertSame(2500.0, $weight->inGrams());
    }

    #[Test]
    public function inGrams_returns_same_for_gram_unit(): void
    {
        $weight = Weight::gram(500.0);

        $this->assertSame(500.0, $weight->inGrams());
    }

    #[Test]
    public function inKilograms_returns_same_for_kg_unit(): void
    {
        $weight = Weight::kilogram(2.5);

        $this->assertSame(2.5, $weight->inKilograms());
    }

    #[Test]
    public function inKilograms_converts_grams_to_kg(): void
    {
        $weight = Weight::gram(2500.0);

        $this->assertSame(2.5, $weight->inKilograms());
    }

    /*
    |--------------------------------------------------------------------------
    | convertTo Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function convertTo_returns_same_instance_for_same_unit(): void
    {
        $weight = Weight::kilogram(2.5);
        $converted = $weight->convertTo(WeightUnit::Kilogram);

        $this->assertSame($weight, $converted);
    }

    #[Test]
    public function convertTo_converts_kg_to_grams(): void
    {
        $weight = Weight::kilogram(2.5);
        $converted = $weight->convertTo(WeightUnit::Gram);

        $this->assertSame(2500.0, $converted->value);
        $this->assertSame(WeightUnit::Gram, $converted->unit);
    }

    #[Test]
    public function convertTo_converts_grams_to_kg(): void
    {
        $weight = Weight::gram(2500.0);
        $converted = $weight->convertTo(WeightUnit::Kilogram);

        $this->assertSame(2.5, $converted->value);
        $this->assertSame(WeightUnit::Kilogram, $converted->unit);
    }

    #[Test]
    public function convertTo_preserves_zero(): void
    {
        $weight = Weight::kilogram(0.0);
        $converted = $weight->convertTo(WeightUnit::Gram);

        $this->assertSame(0.0, $converted->value);
        $this->assertTrue($converted->isEmpty());
    }
}
