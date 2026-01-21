<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductVariationOption::class)]
final class ProductVariationOptionTest extends TestCase
{
    // ========================================================================
    // Happy Path
    // ========================================================================

    #[Test]
    public function it_creates_valid_option(): void
    {
        $option = new ProductVariationOption(
            optionId: 1,
            optionName: 'Color',
            valueId: 10,
            valueName: 'Red',
        );

        self::assertSame(1, $option->optionId);
        self::assertSame('Color', $option->optionName);
        self::assertSame(10, $option->valueId);
        self::assertSame('Red', $option->valueName);
    }

    #[Test]
    public function it_allows_zero_ids_for_shopwired_edge_cases(): void
    {
        // ShopWired can return 0 for option/value IDs in some edge cases
        $option = new ProductVariationOption(
            optionId: 0,
            optionName: 'Size',
            valueId: 0,
            valueName: 'Large',
        );

        self::assertSame(0, $option->optionId);
        self::assertSame(0, $option->valueId);
    }

    // ========================================================================
    // Display String
    // ========================================================================

    #[Test]
    public function to_display_string_formats_correctly(): void
    {
        $option = new ProductVariationOption(
            optionId: 1,
            optionName: 'Color',
            valueId: 10,
            valueName: 'Red',
        );

        self::assertSame('Color: Red', $option->toDisplayString());
    }

    #[Test]
    public function to_display_string_handles_multi_word_values(): void
    {
        $option = new ProductVariationOption(
            optionId: 2,
            optionName: 'Size',
            valueId: 20,
            valueName: 'Extra Large',
        );

        self::assertSame('Size: Extra Large', $option->toDisplayString());
    }

    // ========================================================================
    // Validation
    // ========================================================================

    #[Test]
    public function it_rejects_negative_option_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Option ID cannot be negative');

        new ProductVariationOption(
            optionId: -1,
            optionName: 'Color',
            valueId: 10,
            valueName: 'Red',
        );
    }

    #[Test]
    public function it_rejects_empty_option_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Option name cannot be empty');

        new ProductVariationOption(
            optionId: 1,
            optionName: '',
            valueId: 10,
            valueName: 'Red',
        );
    }

    #[Test]
    public function it_rejects_negative_value_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value ID cannot be negative');

        new ProductVariationOption(
            optionId: 1,
            optionName: 'Color',
            valueId: -1,
            valueName: 'Red',
        );
    }

    #[Test]
    public function it_rejects_empty_value_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value name cannot be empty');

        new ProductVariationOption(
            optionId: 1,
            optionName: 'Color',
            valueId: 10,
            valueName: '',
        );
    }

    // ========================================================================
    // Serialization
    // ========================================================================

    #[Test]
    public function to_array_returns_correct_structure(): void
    {
        $option = new ProductVariationOption(
            optionId: 1,
            optionName: 'Color',
            valueId: 10,
            valueName: 'Red',
        );

        $array = $option->toArray();

        self::assertSame([
            'option_id' => 1,
            'option_name' => 'Color',
            'value_id' => 10,
            'value_name' => 'Red',
        ], $array);
    }

    #[Test]
    public function from_array_creates_valid_option(): void
    {
        $data = [
            'option_id' => 1,
            'option_name' => 'Color',
            'value_id' => 10,
            'value_name' => 'Red',
        ];

        $option = ProductVariationOption::fromArray($data);

        self::assertSame(1, $option->optionId);
        self::assertSame('Color', $option->optionName);
        self::assertSame(10, $option->valueId);
        self::assertSame('Red', $option->valueName);
    }

    #[Test]
    public function round_trip_preserves_data(): void
    {
        $original = new ProductVariationOption(
            optionId: 5,
            optionName: 'Material',
            valueId: 50,
            valueName: 'Cotton',
        );

        $restored = ProductVariationOption::fromArray($original->toArray());

        self::assertSame($original->optionId, $restored->optionId);
        self::assertSame($original->optionName, $restored->optionName);
        self::assertSame($original->valueId, $restored->valueId);
        self::assertSame($original->valueName, $restored->valueName);
    }
}
