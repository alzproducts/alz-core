<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Conversion\CallTracking\Enums;

use App\Domain\Conversion\CallTracking\Enums\CallTrackingActionStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CallTrackingActionStatus::class)]
final class CallTrackingActionStatusTest extends TestCase
{
    #[Test]
    public function backing_values_match_the_check_constraint(): void
    {
        $this->assertSame('pending', CallTrackingActionStatus::Pending->value);
        $this->assertSame('processing', CallTrackingActionStatus::Processing->value);
        $this->assertSame('completed', CallTrackingActionStatus::Completed->value);
        $this->assertSame('failed', CallTrackingActionStatus::Failed->value);
    }

    #[Test]
    public function from_resolves_each_status_string(): void
    {
        $this->assertSame(CallTrackingActionStatus::Pending, CallTrackingActionStatus::from('pending'));
        $this->assertSame(CallTrackingActionStatus::Processing, CallTrackingActionStatus::from('processing'));
        $this->assertSame(CallTrackingActionStatus::Completed, CallTrackingActionStatus::from('completed'));
        $this->assertSame(CallTrackingActionStatus::Failed, CallTrackingActionStatus::from('failed'));
    }

    #[Test]
    public function label_returns_a_human_readable_string_for_each_case(): void
    {
        $this->assertSame('Pending', CallTrackingActionStatus::Pending->label());
        $this->assertSame('Processing', CallTrackingActionStatus::Processing->label());
        $this->assertSame('Completed', CallTrackingActionStatus::Completed->label());
        $this->assertSame('Failed', CallTrackingActionStatus::Failed->label());
    }

    #[Test]
    public function isTerminal_is_true_only_for_completed_and_failed(): void
    {
        $this->assertFalse(CallTrackingActionStatus::Pending->isTerminal());
        $this->assertFalse(CallTrackingActionStatus::Processing->isTerminal());
        $this->assertTrue(CallTrackingActionStatus::Completed->isTerminal());
        $this->assertTrue(CallTrackingActionStatus::Failed->isTerminal());
    }
}
