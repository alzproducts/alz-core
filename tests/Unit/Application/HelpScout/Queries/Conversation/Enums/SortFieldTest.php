<?php

declare(strict_types=1);

namespace Tests\Unit\Application\HelpScout\Queries\Conversation\Enums;

use App\Application\HelpScout\Queries\Conversation\Enums\SortField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(SortField::class)]
final class SortFieldTest extends TestCase
{
    #[Test]
    public function waiting_since_has_correct_value(): void
    {
        $this->assertSame('waitingSince', SortField::WaitingSince->value);
    }

    #[Test]
    public function created_at_has_correct_value(): void
    {
        $this->assertSame('createdAt', SortField::CreatedAt->value);
    }

    #[Test]
    public function modified_at_has_correct_value(): void
    {
        $this->assertSame('modifiedAt', SortField::ModifiedAt->value);
    }

    #[Test]
    public function number_has_correct_value(): void
    {
        $this->assertSame('number', SortField::Number->value);
    }

    #[Test]
    public function enum_has_exactly_four_cases(): void
    {
        $cases = SortField::cases();

        $this->assertCount(4, $cases);
    }

    #[Test]
    public function can_instantiate_from_valid_string(): void
    {
        $this->assertSame(SortField::WaitingSince, SortField::from('waitingSince'));
        $this->assertSame(SortField::CreatedAt, SortField::from('createdAt'));
        $this->assertSame(SortField::ModifiedAt, SortField::from('modifiedAt'));
        $this->assertSame(SortField::Number, SortField::from('number'));
    }

    #[Test]
    public function try_from_returns_null_for_invalid_value(): void
    {
        $this->assertNull(SortField::tryFrom('invalid'));
        $this->assertNull(SortField::tryFrom(''));
    }
}
