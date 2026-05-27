<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Enums;

use App\Domain\Catalog\Product\Enums\ShippingOffersFilterValue;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShippingOffersFilterValue::class)]
final class ShippingOffersFilterValueTest extends TestCase
{
    #[Test]
    public function free_standard_case_uses_full_phrase_backing_value(): void
    {
        self::assertSame('Free Standard Delivery', ShippingOffersFilterValue::FreeStandardDelivery->value);
    }

    #[Test]
    public function free_express_case_uses_full_phrase_backing_value(): void
    {
        self::assertSame('Free Express Delivery', ShippingOffersFilterValue::FreeExpressDelivery->value);
    }

    /**
     * @param non-empty-string $value
     */
    #[Test]
    #[DataProvider('caseRoundTripProvider')]
    public function from_string_round_trips_each_case(string $value, ShippingOffersFilterValue $expected): void
    {
        self::assertSame($expected, ShippingOffersFilterValue::fromString($value));
    }

    /**
     * @return array<string, array{0: string, 1: ShippingOffersFilterValue}>
     */
    public static function caseRoundTripProvider(): array
    {
        return [
            'standard' => ['Free Standard Delivery', ShippingOffersFilterValue::FreeStandardDelivery],
            'express' => ['Free Express Delivery', ShippingOffersFilterValue::FreeExpressDelivery],
        ];
    }

    #[Test]
    public function from_string_throws_for_unknown_value(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        ShippingOffersFilterValue::fromString('Paid Delivery');
    }

    #[Test]
    public function from_json_array_parses_both_cases(): void
    {
        self::assertSame(
            [
                ShippingOffersFilterValue::FreeStandardDelivery,
                ShippingOffersFilterValue::FreeExpressDelivery,
            ],
            ShippingOffersFilterValue::fromJsonArray('["Free Standard Delivery", "Free Express Delivery"]'),
        );
    }

    #[Test]
    public function from_json_array_returns_empty_list_for_empty_json_array(): void
    {
        self::assertSame([], ShippingOffersFilterValue::fromJsonArray('[]'));
    }

    #[Test]
    public function from_json_array_throws_for_non_array_json(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        ShippingOffersFilterValue::fromJsonArray('"Free Standard Delivery"');
    }

    #[Test]
    public function from_json_array_throws_for_malformed_json(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        ShippingOffersFilterValue::fromJsonArray('not json');
    }

    #[Test]
    public function from_json_array_throws_for_non_string_element(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        ShippingOffersFilterValue::fromJsonArray('[42]');
    }
}
