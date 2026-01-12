<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\ValueObjects\ProductVariation;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ProductVariation Value Object Unit Tests.
 *
 * Tests the ProductVariation domain value object including construction,
 * assertions, and array conversion methods.
 */
#[CoversClass(ProductVariation::class)]
final class ProductVariationTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_variation_with_valid_data(): void
    {
        $variation = new ProductVariation(name: 'Colour', value: 'Ivory');

        $this->assertSame('Colour', $variation->name);
        $this->assertSame('Ivory', $variation->value);
    }

    #[Test]
    public function it_accepts_empty_value(): void
    {
        $variation = new ProductVariation(name: 'Size', value: '');

        $this->assertSame('Size', $variation->name);
        $this->assertSame('', $variation->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Name Assertion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('emptyNameProvider')]
    public function it_throws_if_name_is_empty(string $emptyName): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Variation name cannot be empty');

        new ProductVariation(name: $emptyName, value: 'Blue');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptyNameProvider(): array
    {
        return [
            'empty string' => [''],
        ];
    }

    #[Test]
    public function it_accepts_non_empty_name(): void
    {
        $variation = new ProductVariation(name: 'A', value: 'B');

        $this->assertSame('A', $variation->name);
    }

    /*
    |--------------------------------------------------------------------------
    | fromArray() Factory Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_array_creates_valid_variation(): void
    {
        $data = ['name' => 'Material', 'value' => 'Cotton'];

        $variation = ProductVariation::fromArray($data);

        $this->assertSame('Material', $variation->name);
        $this->assertSame('Cotton', $variation->value);
    }

    #[Test]
    public function from_array_throws_on_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Variation name cannot be empty');

        ProductVariation::fromArray(['name' => '', 'value' => 'Cotton']);
    }

    /*
    |--------------------------------------------------------------------------
    | toArray() Serialization Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_array_returns_expected_structure(): void
    {
        $variation = new ProductVariation(name: 'Finish', value: 'Matte');

        $result = $variation->toArray();

        $this->assertSame(['name' => 'Finish', 'value' => 'Matte'], $result);
    }

    #[Test]
    public function to_array_roundtrip_preserves_data(): void
    {
        $original = new ProductVariation(name: 'Weight', value: '2kg');

        $recreated = ProductVariation::fromArray($original->toArray());

        $this->assertSame($original->name, $recreated->name);
        $this->assertSame($original->value, $recreated->value);
    }
}
