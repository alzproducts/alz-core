<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Enums;

use App\Domain\Catalog\Product\Enums\OffersFilterValue;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OffersFilterValue::class)]
final class OffersFilterValueTest extends TestCase
{
    #[Test]
    public function on_sale_case_uses_title_case_backing_value(): void
    {
        self::assertSame('On Sale', OffersFilterValue::OnSale->value);
    }

    #[Test]
    public function from_string_returns_case_for_title_case_value(): void
    {
        self::assertSame(OffersFilterValue::OnSale, OffersFilterValue::fromString('On Sale'));
    }

    #[Test]
    public function from_string_throws_for_legacy_lowercase_value(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        OffersFilterValue::fromString('On sale');
    }

    #[Test]
    public function from_string_throws_for_unknown_value(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        OffersFilterValue::fromString('Free Delivery');
    }

    #[Test]
    public function from_json_array_parses_single_value(): void
    {
        self::assertSame(
            [OffersFilterValue::OnSale],
            OffersFilterValue::fromJsonArray('["On Sale"]'),
        );
    }

    #[Test]
    public function from_json_array_returns_empty_list_for_empty_json_array(): void
    {
        self::assertSame([], OffersFilterValue::fromJsonArray('[]'));
    }

    #[Test]
    public function from_json_array_throws_for_non_array_json(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        OffersFilterValue::fromJsonArray('"On Sale"');
    }

    #[Test]
    public function from_json_array_throws_for_malformed_json(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        OffersFilterValue::fromJsonArray('not json');
    }

    #[Test]
    public function from_json_array_throws_for_non_string_element(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        OffersFilterValue::fromJsonArray('[42]');
    }

    #[Test]
    public function from_json_array_throws_for_unknown_string_element(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        OffersFilterValue::fromJsonArray('["Unknown"]');
    }
}
