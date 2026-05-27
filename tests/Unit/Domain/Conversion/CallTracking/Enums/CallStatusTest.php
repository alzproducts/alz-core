<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Conversion\CallTracking\Enums;

use App\Domain\Conversion\CallTracking\Enums\CallStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CallStatus::class)]
final class CallStatusTest extends TestCase
{
    #[Test]
    public function it_exposes_the_initiated_case_with_the_check_constraint_value(): void
    {
        $this->assertSame('initiated', CallStatus::Initiated->value);
    }

    #[Test]
    public function it_constructs_from_the_string_value(): void
    {
        $this->assertSame(CallStatus::Initiated, CallStatus::from('initiated'));
    }

    #[Test]
    public function tryFrom_returns_null_for_an_unknown_value(): void
    {
        $this->assertNull(CallStatus::tryFrom('answered'));
    }
}
