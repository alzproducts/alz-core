<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\Enums;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldValidationRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomFieldValidationRule::class)]
final class CustomFieldValidationRuleTest extends TestCase
{
    #[Test]
    public function exposes_stable_int_values(): void
    {
        self::assertSame(1, CustomFieldValidationRule::Url->value);
        self::assertSame(2, CustomFieldValidationRule::AlphaNumeric->value);
        self::assertSame(3, CustomFieldValidationRule::Integer->value);
    }

    #[Test]
    public function from_parses_known_values(): void
    {
        self::assertSame(CustomFieldValidationRule::Url, CustomFieldValidationRule::from(1));
        self::assertSame(CustomFieldValidationRule::AlphaNumeric, CustomFieldValidationRule::from(2));
        self::assertSame(CustomFieldValidationRule::Integer, CustomFieldValidationRule::from(3));
    }

    #[Test]
    public function try_from_returns_null_for_unknown_value(): void
    {
        self::assertNull(CustomFieldValidationRule::tryFrom(0));
        self::assertNull(CustomFieldValidationRule::tryFrom(99));
    }

    #[Test]
    public function defines_exactly_three_cases(): void
    {
        self::assertCount(3, CustomFieldValidationRule::cases());
    }
}
