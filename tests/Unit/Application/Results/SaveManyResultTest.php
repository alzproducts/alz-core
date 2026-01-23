<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Results;

use App\Application\Results\SaveManyResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SaveManyResult Value Object Unit Tests.
 *
 * Tests the business logic methods: failure detection, success checking, and total calculation.
 */
#[CoversClass(SaveManyResult::class)]
final class SaveManyResultTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | hasFailures() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_failures_returns_true_when_failures_exist(): void
    {
        $result = new SaveManyResult(succeeded: 5, failed: 2, failedReferences: [123, 456]);

        $this->assertTrue($result->hasFailures());
    }

    #[Test]
    public function has_failures_returns_false_when_no_failures(): void
    {
        $result = new SaveManyResult(succeeded: 5, failed: 0);

        $this->assertFalse($result->hasFailures());
    }

    /*
    |--------------------------------------------------------------------------
    | allSucceeded() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function all_succeeded_returns_true_when_no_failures(): void
    {
        $result = new SaveManyResult(succeeded: 10, failed: 0);

        $this->assertTrue($result->allSucceeded());
    }

    #[Test]
    public function all_succeeded_returns_false_when_any_failures(): void
    {
        $result = new SaveManyResult(succeeded: 8, failed: 2, failedReferences: [123, 456]);

        $this->assertFalse($result->allSucceeded());
    }

    /*
    |--------------------------------------------------------------------------
    | total() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function total_sums_succeeded_and_failed(): void
    {
        $result = new SaveManyResult(succeeded: 7, failed: 3, failedReferences: [1, 2, 3]);

        $this->assertSame(10, $result->total());
    }

    #[Test]
    public function total_returns_succeeded_when_no_failures(): void
    {
        $result = new SaveManyResult(succeeded: 5, failed: 0);

        $this->assertSame(5, $result->total());
    }

    /*
    |--------------------------------------------------------------------------
    | Factory Method Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function success_factory_creates_zero_failures_result(): void
    {
        $result = SaveManyResult::success(15);

        $this->assertSame(15, $result->succeeded);
        $this->assertSame(0, $result->failed);
        $this->assertSame([], $result->failedReferences);
        $this->assertTrue($result->allSucceeded());
    }

    #[Test]
    public function failure_factory_creates_zero_succeeded_result(): void
    {
        $failedRefs = [100, 200, 300];
        $result = SaveManyResult::failure($failedRefs);

        $this->assertSame(0, $result->succeeded);
        $this->assertSame(3, $result->failed);
        $this->assertSame($failedRefs, $result->failedReferences);
        $this->assertTrue($result->hasFailures());
    }
}
