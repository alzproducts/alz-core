<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\Enums;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldValueSelectType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomFieldValueSelectType::class)]
final class CustomFieldValueSelectTypeTest extends TestCase
{
    #[Test]
    public function exposes_stable_string_values(): void
    {
        self::assertSame('category', CustomFieldValueSelectType::Category->value);
        self::assertSame('brand', CustomFieldValueSelectType::Brand->value);
        self::assertSame('product', CustomFieldValueSelectType::Product->value);
    }

    #[Test]
    public function from_parses_known_values(): void
    {
        self::assertSame(CustomFieldValueSelectType::Category, CustomFieldValueSelectType::from('category'));
        self::assertSame(CustomFieldValueSelectType::Brand, CustomFieldValueSelectType::from('brand'));
        self::assertSame(CustomFieldValueSelectType::Product, CustomFieldValueSelectType::from('product'));
    }

    #[Test]
    public function try_from_returns_null_for_unknown_value(): void
    {
        self::assertNull(CustomFieldValueSelectType::tryFrom('customer'));
        self::assertNull(CustomFieldValueSelectType::tryFrom(''));
    }

    #[Test]
    public function defines_exactly_three_cases(): void
    {
        self::assertCount(3, CustomFieldValueSelectType::cases());
    }
}
