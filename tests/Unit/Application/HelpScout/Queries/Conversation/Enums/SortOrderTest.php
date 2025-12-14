<?php

declare(strict_types=1);

namespace Tests\Unit\Application\HelpScout\Queries\Conversation\Enums;

use App\Application\HelpScout\Queries\Conversation\Enums\SortOrder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(SortOrder::class)]
final class SortOrderTest extends TestCase
{
    #[Test]
    public function asc_has_correct_value(): void
    {
        $this->assertSame('asc', SortOrder::Asc->value);
    }

    #[Test]
    public function desc_has_correct_value(): void
    {
        $this->assertSame('desc', SortOrder::Desc->value);
    }

    #[Test]
    public function enum_has_exactly_two_cases(): void
    {
        $cases = SortOrder::cases();

        $this->assertCount(2, $cases);
    }

    #[Test]
    public function can_instantiate_from_valid_string(): void
    {
        $this->assertSame(SortOrder::Asc, SortOrder::from('asc'));
        $this->assertSame(SortOrder::Desc, SortOrder::from('desc'));
    }

    #[Test]
    public function try_from_returns_null_for_invalid_value(): void
    {
        $this->assertNull(SortOrder::tryFrom('ascending'));
        $this->assertNull(SortOrder::tryFrom('DESC')); // Case sensitive
        $this->assertNull(SortOrder::tryFrom(''));
    }
}
