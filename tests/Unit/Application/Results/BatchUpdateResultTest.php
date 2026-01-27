<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Results;

use App\Application\Results\BatchUpdateResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BatchUpdateResult::class)]
final class BatchUpdateResultTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Counting Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function failed_returns_sum_of_permanent_and_temporary(): void
    {
        $result = new BatchUpdateResult(
            total: 5,
            succeeded: 2,
            permanentFailures: [
                ['identifier' => 'SKU-1', 'error' => 'Not found'],
            ],
            temporaryFailures: [
                ['identifier' => 'SKU-2', 'error' => 'Timeout'],
                ['identifier' => 'SKU-3', 'error' => 'Unavailable'],
            ],
        );

        self::assertSame(3, $result->failed());
    }

    #[Test]
    public function failed_returns_zero_when_no_failures(): void
    {
        $result = new BatchUpdateResult(total: 3, succeeded: 3);

        self::assertSame(0, $result->failed());
    }

    /*
    |--------------------------------------------------------------------------
    | Boolean Status Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_failures_returns_true_with_permanent_failures(): void
    {
        $result = new BatchUpdateResult(
            total: 2,
            succeeded: 1,
            permanentFailures: [['identifier' => 'SKU-1', 'error' => 'err']],
        );

        self::assertTrue($result->hasFailures());
    }

    #[Test]
    public function has_failures_returns_true_with_temporary_failures(): void
    {
        $result = new BatchUpdateResult(
            total: 2,
            succeeded: 1,
            temporaryFailures: [['identifier' => 'SKU-1', 'error' => 'err']],
        );

        self::assertTrue($result->hasFailures());
    }

    #[Test]
    public function has_failures_returns_false_with_no_failures(): void
    {
        $result = new BatchUpdateResult(total: 2, succeeded: 2);

        self::assertFalse($result->hasFailures());
    }

    #[Test]
    public function has_retryable_failures_returns_true_only_for_temporary(): void
    {
        $withTemp = new BatchUpdateResult(
            total: 2,
            succeeded: 1,
            temporaryFailures: [['identifier' => 'SKU-1', 'error' => 'err']],
        );
        $withPerm = new BatchUpdateResult(
            total: 2,
            succeeded: 1,
            permanentFailures: [['identifier' => 'SKU-1', 'error' => 'err']],
        );
        $noFailures = new BatchUpdateResult(total: 2, succeeded: 2);

        self::assertTrue($withTemp->hasRetryableFailures());
        self::assertFalse($withPerm->hasRetryableFailures());
        self::assertFalse($noFailures->hasRetryableFailures());
    }

    #[Test]
    public function all_succeeded_returns_true_when_no_failures(): void
    {
        $allGood = new BatchUpdateResult(total: 3, succeeded: 3);
        $hasFailure = new BatchUpdateResult(
            total: 3,
            succeeded: 2,
            permanentFailures: [['identifier' => 'X', 'error' => 'err']],
        );

        self::assertTrue($allGood->allSucceeded());
        self::assertFalse($hasFailure->allSucceeded());
    }

    #[Test]
    public function all_failed_returns_true_when_none_succeeded(): void
    {
        $allBad = new BatchUpdateResult(
            total: 2,
            succeeded: 0,
            permanentFailures: [
                ['identifier' => 'X', 'error' => 'err'],
                ['identifier' => 'Y', 'error' => 'err'],
            ],
        );
        $someGood = new BatchUpdateResult(
            total: 2,
            succeeded: 1,
            permanentFailures: [['identifier' => 'X', 'error' => 'err']],
        );
        $empty = BatchUpdateResult::empty();

        self::assertTrue($allBad->allFailed());
        self::assertFalse($someGood->allFailed());
        self::assertFalse($empty->allFailed()); // total=0, so not "all failed"
    }

    #[Test]
    public function is_partial_success_returns_true_for_mixed_results(): void
    {
        $partial = new BatchUpdateResult(
            total: 3,
            succeeded: 2,
            permanentFailures: [['identifier' => 'X', 'error' => 'err']],
        );
        $allGood = new BatchUpdateResult(total: 3, succeeded: 3);
        $allBad = new BatchUpdateResult(
            total: 2,
            succeeded: 0,
            permanentFailures: [['identifier' => 'X', 'error' => 'err']],
        );

        self::assertTrue($partial->isPartialSuccess());
        self::assertFalse($allGood->isPartialSuccess());
        self::assertFalse($allBad->isPartialSuccess());
    }

    /*
    |--------------------------------------------------------------------------
    | Identifier Extraction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_retryable_identifiers_returns_temporary_failure_ids(): void
    {
        $result = new BatchUpdateResult(
            total: 4,
            succeeded: 1,
            permanentFailures: [['identifier' => 'PERM-1', 'error' => 'err']],
            temporaryFailures: [
                ['identifier' => 'TEMP-1', 'error' => 'err'],
                ['identifier' => 12345, 'error' => 'err'],
            ],
        );

        self::assertSame(['TEMP-1', 12345], $result->getRetryableIdentifiers());
    }

    #[Test]
    public function get_all_failures_combines_with_retryable_flag(): void
    {
        $result = new BatchUpdateResult(
            total: 3,
            succeeded: 0,
            permanentFailures: [['identifier' => 'P1', 'error' => 'perm error']],
            temporaryFailures: [['identifier' => 'T1', 'error' => 'temp error']],
        );

        $all = $result->getAllFailures();

        self::assertCount(2, $all);
        self::assertSame('P1', $all[0]['identifier']);
        self::assertFalse($all[0]['retryable']);
        self::assertSame('T1', $all[1]['identifier']);
        self::assertTrue($all[1]['retryable']);
    }

    /*
    |--------------------------------------------------------------------------
    | Factory Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function empty_creates_zero_result(): void
    {
        $result = BatchUpdateResult::empty();

        self::assertSame(0, $result->total);
        self::assertSame(0, $result->succeeded);
        self::assertSame([], $result->permanentFailures);
        self::assertSame([], $result->temporaryFailures);
    }
}
