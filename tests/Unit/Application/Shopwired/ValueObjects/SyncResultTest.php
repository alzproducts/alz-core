<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\ValueObjects;

use App\Application\ValueObjects\SyncResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SyncResult Value Object Unit Tests.
 *
 * Tests the business logic methods: failure detection, success checking, and empty state.
 */
#[CoversClass(SyncResult::class)]
final class SyncResultTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | hasFailures() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_failures_returns_true_when_failures_exist(): void
    {
        $result = new SyncResult(fetched: 10, saved: 8, failed: 2, failedReferences: [123, 456]);

        $this->assertTrue($result->hasFailures());
    }

    #[Test]
    public function has_failures_returns_false_when_no_failures(): void
    {
        $result = new SyncResult(fetched: 10, saved: 10, failed: 0);

        $this->assertFalse($result->hasFailures());
    }

    /*
    |--------------------------------------------------------------------------
    | allSaved() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function all_saved_returns_true_when_fetched_equals_saved_with_no_failures(): void
    {
        $result = new SyncResult(fetched: 10, saved: 10, failed: 0);

        $this->assertTrue($result->allSaved());
    }

    #[Test]
    public function all_saved_returns_false_when_any_failures(): void
    {
        $result = new SyncResult(fetched: 10, saved: 8, failed: 2, failedReferences: [1, 2]);

        $this->assertFalse($result->allSaved());
    }

    #[Test]
    public function all_saved_returns_false_when_saved_does_not_match_fetched(): void
    {
        // Edge case: failed=0 but saved != fetched (shouldn't happen, but test the logic)
        $result = new SyncResult(fetched: 10, saved: 8, failed: 0);

        $this->assertFalse($result->allSaved());
    }

    /*
    |--------------------------------------------------------------------------
    | isEmpty() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function is_empty_returns_true_when_nothing_fetched(): void
    {
        $result = new SyncResult(fetched: 0, saved: 0, failed: 0);

        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function is_empty_returns_false_when_items_fetched(): void
    {
        $result = new SyncResult(fetched: 5, saved: 5, failed: 0);

        $this->assertFalse($result->isEmpty());
    }

    /*
    |--------------------------------------------------------------------------
    | Factory Method Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function empty_factory_creates_all_zero_result(): void
    {
        $result = SyncResult::empty();

        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->saved);
        $this->assertSame(0, $result->failed);
        $this->assertSame([], $result->failedReferences);
        $this->assertTrue($result->isEmpty());
        $this->assertTrue($result->allSaved());
        $this->assertFalse($result->hasFailures());
    }
}
