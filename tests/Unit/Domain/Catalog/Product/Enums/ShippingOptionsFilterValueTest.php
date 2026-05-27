<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Enums;

use App\Domain\Catalog\Product\Enums\ShippingOptionsFilterValue;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShippingOptionsFilterValue::class)]
final class ShippingOptionsFilterValueTest extends TestCase
{
    #[Test]
    public function next_day_delivery_case_uses_full_phrase_backing_value(): void
    {
        self::assertSame('Next Day Delivery Available', ShippingOptionsFilterValue::NextDayDeliveryAvailable->value);
    }

    #[Test]
    public function from_string_returns_case_for_known_value(): void
    {
        self::assertSame(
            ShippingOptionsFilterValue::NextDayDeliveryAvailable,
            ShippingOptionsFilterValue::fromString('Next Day Delivery Available'),
        );
    }

    #[Test]
    public function from_string_throws_for_unknown_value(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        ShippingOptionsFilterValue::fromString('Standard Delivery');
    }

    #[Test]
    public function from_json_array_parses_single_value(): void
    {
        self::assertSame(
            [ShippingOptionsFilterValue::NextDayDeliveryAvailable],
            ShippingOptionsFilterValue::fromJsonArray('["Next Day Delivery Available"]'),
        );
    }

    #[Test]
    public function from_json_array_returns_empty_list_for_empty_json_array(): void
    {
        self::assertSame([], ShippingOptionsFilterValue::fromJsonArray('[]'));
    }

    #[Test]
    public function from_json_array_throws_for_non_array_json(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        ShippingOptionsFilterValue::fromJsonArray('"Next Day Delivery Available"');
    }

    #[Test]
    public function from_json_array_throws_for_malformed_json(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        ShippingOptionsFilterValue::fromJsonArray('not json');
    }

    #[Test]
    public function from_json_array_throws_for_non_string_element(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        ShippingOptionsFilterValue::fromJsonArray('[42]');
    }

    #[Test]
    public function from_json_array_throws_for_unknown_string_element(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        ShippingOptionsFilterValue::fromJsonArray('["Standard"]');
    }
}
