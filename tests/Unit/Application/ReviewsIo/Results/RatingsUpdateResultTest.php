<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ReviewsIo\Results;

use App\Application\ReviewsIo\Results\RatingsUpdateResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RatingsUpdateResult Unit Tests.
 *
 * Tests the hasFailures() business logic method.
 */
#[CoversClass(RatingsUpdateResult::class)]
final class RatingsUpdateResultTest extends TestCase
{
    #[Test]
    public function has_failures_returns_true_when_failures_exist(): void
    {
        $result = new RatingsUpdateResult(
            processed: 10,
            updated: 8,
            skipped: 0,
            failed: 2,
            failedProductIds: [123, 456],
        );

        $this->assertTrue($result->hasFailures());
    }

    #[Test]
    public function has_failures_returns_false_when_no_failures(): void
    {
        $result = new RatingsUpdateResult(
            processed: 10,
            updated: 10,
            skipped: 0,
            failed: 0,
        );

        $this->assertFalse($result->hasFailures());
    }

    #[Test]
    public function has_failures_returns_false_when_all_skipped(): void
    {
        $result = new RatingsUpdateResult(
            processed: 10,
            updated: 0,
            skipped: 10,
            failed: 0,
        );

        $this->assertFalse($result->hasFailures());
    }
}
