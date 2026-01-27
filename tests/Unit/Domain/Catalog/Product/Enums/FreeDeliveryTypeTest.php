<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Enums;

use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ValueError;

#[CoversClass(FreeDeliveryType::class)]
final class FreeDeliveryTypeTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | fromString() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('validStringProvider')]
    public function it_creates_from_valid_string(string $input, FreeDeliveryType $expected): void
    {
        self::assertSame($expected, FreeDeliveryType::fromString($input));
    }

    /**
     * @return array<string, array{string, FreeDeliveryType}>
     */
    public static function validStringProvider(): array
    {
        return [
            'none lowercase' => ['none', FreeDeliveryType::None],
            'None mixed case' => ['None', FreeDeliveryType::None],
            'NONE uppercase' => ['NONE', FreeDeliveryType::None],
            'empty string' => ['', FreeDeliveryType::None],
            'standard lowercase' => ['standard', FreeDeliveryType::Standard],
            'Standard mixed case' => ['Standard', FreeDeliveryType::Standard],
            'STANDARD uppercase' => ['STANDARD', FreeDeliveryType::Standard],
            'express lowercase' => ['express', FreeDeliveryType::Express],
            'Express mixed case' => ['Express', FreeDeliveryType::Express],
            'EXPRESS uppercase' => ['EXPRESS', FreeDeliveryType::Express],
            'whitespace trimmed' => ['  standard  ', FreeDeliveryType::Standard],
        ];
    }

    #[Test]
    public function it_throws_for_invalid_string(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Invalid free delivery type: invalid');

        FreeDeliveryType::fromString('invalid');
    }

    /*
    |--------------------------------------------------------------------------
    | toStringOrEmpty() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('toStringOrEmptyProvider')]
    public function it_returns_correct_string_or_empty(FreeDeliveryType $type, string $expected): void
    {
        self::assertSame($expected, $type->toStringOrEmpty());
    }

    /**
     * @return array<string, array{FreeDeliveryType, string}>
     */
    public static function toStringOrEmptyProvider(): array
    {
        return [
            'None returns empty string' => [FreeDeliveryType::None, ''],
            'Standard returns Standard' => [FreeDeliveryType::Standard, 'Standard'],
            'Express returns Express' => [FreeDeliveryType::Express, 'Express'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | isNone() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function is_none_returns_true_only_for_none(): void
    {
        self::assertTrue(FreeDeliveryType::None->isNone());
        self::assertFalse(FreeDeliveryType::Standard->isNone());
        self::assertFalse(FreeDeliveryType::Express->isNone());
    }

    /*
    |--------------------------------------------------------------------------
    | selectableValues() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function selectable_values_excludes_none(): void
    {
        $values = FreeDeliveryType::selectableValues();

        self::assertSame(['Standard', 'Express'], $values);
        self::assertNotContains('none', $values);
    }

    /*
    |--------------------------------------------------------------------------
    | Enum Structure Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function enum_has_exactly_three_cases(): void
    {
        self::assertCount(3, FreeDeliveryType::cases());
    }

    #[Test]
    public function enum_values_match_expected(): void
    {
        self::assertSame('none', FreeDeliveryType::None->value);
        self::assertSame('Standard', FreeDeliveryType::Standard->value);
        self::assertSame('Express', FreeDeliveryType::Express->value);
    }
}
