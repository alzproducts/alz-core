<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\ValueObjects;

use App\Domain\Inventory\ValueObjects\Dimensions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dimensions Value Object Unit Tests.
 *
 * Tests dimension validation, volume calculation, and factory methods.
 */
#[CoversClass(Dimensions::class)]
final class DimensionsTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_dimensions_with_all_values(): void
    {
        $dimensions = new Dimensions(10.0, 5.0, 3.0);

        $this->assertSame(10.0, $dimensions->height);
        $this->assertSame(5.0, $dimensions->width);
        $this->assertSame(3.0, $dimensions->depth);
    }

    #[Test]
    public function it_accepts_zero_dimensions(): void
    {
        $dimensions = new Dimensions(0.0, 0.0, 0.0);

        $this->assertSame(0.0, $dimensions->height);
        $this->assertSame(0.0, $dimensions->width);
        $this->assertSame(0.0, $dimensions->depth);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_for_negative_height(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Height cannot be negative');

        new Dimensions(-1.0, 5.0, 3.0);
    }

    #[Test]
    public function it_throws_for_negative_width(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Width cannot be negative');

        new Dimensions(10.0, -1.0, 3.0);
    }

    #[Test]
    public function it_throws_for_negative_depth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Depth cannot be negative');

        new Dimensions(10.0, 5.0, -1.0);
    }

    /*
    |--------------------------------------------------------------------------
    | Factory Method Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function zero_creates_zero_dimensions(): void
    {
        $dimensions = Dimensions::zero();

        $this->assertSame(0.0, $dimensions->height);
        $this->assertSame(0.0, $dimensions->width);
        $this->assertSame(0.0, $dimensions->depth);
    }

    /*
    |--------------------------------------------------------------------------
    | isEmpty Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function isEmpty_returns_true_when_all_dimensions_are_zero(): void
    {
        $this->assertTrue(Dimensions::zero()->isEmpty());
        $this->assertTrue((new Dimensions(0.0, 0.0, 0.0))->isEmpty());
    }

    #[Test]
    #[DataProvider('nonEmptyDimensionsProvider')]
    public function isEmpty_returns_false_when_any_dimension_is_non_zero(float $h, float $w, float $d): void
    {
        $dimensions = new Dimensions($h, $w, $d);

        $this->assertFalse($dimensions->isEmpty());
    }

    /**
     * @return array<string, array{float, float, float}>
     */
    public static function nonEmptyDimensionsProvider(): array
    {
        return [
            'only height' => [1.0, 0.0, 0.0],
            'only width' => [0.0, 1.0, 0.0],
            'only depth' => [0.0, 0.0, 1.0],
            'all non-zero' => [1.0, 2.0, 3.0],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Volume Calculation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function volume_calculates_cubic_volume(): void
    {
        $dimensions = new Dimensions(10.0, 5.0, 2.0);

        $this->assertSame(100.0, $dimensions->volume()); // 10 * 5 * 2
    }

    #[Test]
    public function volume_returns_zero_when_any_dimension_is_zero(): void
    {
        $this->assertSame(0.0, Dimensions::zero()->volume());
        $this->assertSame(0.0, (new Dimensions(10.0, 0.0, 5.0))->volume());
        $this->assertSame(0.0, (new Dimensions(0.0, 5.0, 5.0))->volume());
        $this->assertSame(0.0, (new Dimensions(10.0, 5.0, 0.0))->volume());
    }

    #[Test]
    public function volume_handles_decimal_precision(): void
    {
        $dimensions = new Dimensions(2.5, 4.0, 3.0);

        $this->assertSame(30.0, $dimensions->volume()); // 2.5 * 4 * 3
    }

    #[Test]
    public function volume_handles_small_decimals(): void
    {
        $dimensions = new Dimensions(0.1, 0.1, 0.1);

        // 0.1 * 0.1 * 0.1 = 0.001 (but floating point may vary)
        $this->assertEqualsWithDelta(0.001, $dimensions->volume(), 0.0001);
    }
}
