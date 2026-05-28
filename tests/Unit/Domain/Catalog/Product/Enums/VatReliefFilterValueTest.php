<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Enums;

use App\Domain\Catalog\Product\Enums\VatReliefFilterValue;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VatReliefFilterValue::class)]
final class VatReliefFilterValueTest extends TestCase
{
    #[Test]
    public function yes_case_uses_yes_backing_value(): void
    {
        self::assertSame('Yes', VatReliefFilterValue::Yes->value);
    }

    #[Test]
    public function from_string_returns_yes_case_for_yes_value(): void
    {
        self::assertSame(VatReliefFilterValue::Yes, VatReliefFilterValue::fromString('Yes'));
    }

    #[Test]
    public function from_string_throws_for_lowercase_value(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        VatReliefFilterValue::fromString('yes');
    }

    #[Test]
    public function from_string_throws_for_no_value(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        VatReliefFilterValue::fromString('No');
    }

    #[Test]
    public function from_postgres_array_returns_empty_list_for_empty_braces(): void
    {
        self::assertSame([], VatReliefFilterValue::fromPostgresArray('{}'));
    }

    #[Test]
    public function from_postgres_array_returns_empty_list_for_empty_string(): void
    {
        self::assertSame([], VatReliefFilterValue::fromPostgresArray(''));
    }

    #[Test]
    public function from_postgres_array_parses_single_value(): void
    {
        self::assertSame(
            [VatReliefFilterValue::Yes],
            VatReliefFilterValue::fromPostgresArray('{Yes}'),
        );
    }

    #[Test]
    public function from_postgres_array_throws_for_unknown_value(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        VatReliefFilterValue::fromPostgresArray('{No}');
    }
}
