<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\PricingUpdate\Results;

use App\Application\Shopwired\PricingUpdate\Results\BatchApiResult;
use App\Application\Shopwired\PricingUpdate\Results\FailedPriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\Results\PreFlightValidationResult;
use App\Application\Shopwired\PricingUpdate\Results\PriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\Results\SkippedPriceUpdateResult;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PriceUpdateResult::class)]
final class PriceUpdateResultTest extends TestCase
{
    // ========================================================================
    // fromPhases() — without API result
    // ========================================================================

    #[Test]
    public function from_phases_without_api_result_returns_zero_succeeded(): void
    {
        $preFlight = new PreFlightValidationResult(
            validated: [],
            skipped: [new SkippedPriceUpdateResult(Sku::fromTrusted('SKU-1'), 'unchanged')],
            permanentFailures: [new FailedPriceUpdateResult(Sku::fromTrusted('SKU-2'), 'invalid')],
        );

        $result = PriceUpdateResult::fromPhases(2, $preFlight, null);

        self::assertSame(2, $result->total);
        self::assertSame(0, $result->succeeded);
        self::assertCount(1, $result->skipped);
        self::assertCount(1, $result->permanentFailures);
        self::assertSame([], $result->temporaryFailures);
    }

    // ========================================================================
    // fromPhases() — with API result
    // ========================================================================

    #[Test]
    public function from_phases_with_api_result_merges_failures(): void
    {
        $preFlight = new PreFlightValidationResult(
            validated: [],
            skipped: [],
            permanentFailures: [new FailedPriceUpdateResult(Sku::fromTrusted('SKU-1'), 'ownership')],
        );

        $apiResult = new BatchApiResult(
            updatedSkus: [Sku::fromTrusted('SKU-2')],
            permanentFailures: [new FailedPriceUpdateResult(Sku::fromTrusted('SKU-3'), 'API rejected')],
            temporaryFailures: [new FailedPriceUpdateResult(null, 'timeout')],
        );

        $result = PriceUpdateResult::fromPhases(3, $preFlight, $apiResult);

        self::assertSame(3, $result->total);
        self::assertSame(1, $result->succeeded);
        self::assertCount(2, $result->permanentFailures);
        self::assertCount(1, $result->temporaryFailures);
    }

    // ========================================================================
    // hasFailures()
    // ========================================================================

    #[Test]
    public function has_failures_with_permanent_only(): void
    {
        $result = new PriceUpdateResult(
            total: 1,
            succeeded: 0,
            permanentFailures: [new FailedPriceUpdateResult(Sku::fromTrusted('SKU-1'), 'err')],
        );

        self::assertTrue($result->hasFailures());
    }

    #[Test]
    public function has_failures_with_temporary_only(): void
    {
        $result = new PriceUpdateResult(
            total: 1,
            succeeded: 0,
            temporaryFailures: [new FailedPriceUpdateResult(null, 'timeout')],
        );

        self::assertTrue($result->hasFailures());
    }

    #[Test]
    public function has_no_failures_when_both_empty(): void
    {
        $result = new PriceUpdateResult(total: 1, succeeded: 1);

        self::assertFalse($result->hasFailures());
    }

    // ========================================================================
    // allSucceeded()
    // ========================================================================

    #[Test]
    public function all_succeeded_when_no_failures_and_count_matches(): void
    {
        $result = new PriceUpdateResult(total: 3, succeeded: 3);

        self::assertTrue($result->allSucceeded());
    }

    #[Test]
    public function all_succeeded_false_when_has_failures(): void
    {
        $result = new PriceUpdateResult(
            total: 3,
            succeeded: 2,
            temporaryFailures: [new FailedPriceUpdateResult(null, 'timeout')],
        );

        self::assertFalse($result->allSucceeded());
    }

    #[Test]
    public function all_succeeded_false_when_count_mismatch(): void
    {
        // e.g., some skipped — succeeded doesn't match total
        $result = new PriceUpdateResult(
            total: 3,
            succeeded: 2,
            skipped: [new SkippedPriceUpdateResult(Sku::fromTrusted('SKU-1'), 'unchanged')],
        );

        self::assertFalse($result->allSucceeded());
    }

    // ========================================================================
    // isPartialSuccess()
    // ========================================================================

    #[Test]
    public function is_partial_success_when_some_succeeded_and_has_failures(): void
    {
        $result = new PriceUpdateResult(
            total: 3,
            succeeded: 1,
            permanentFailures: [new FailedPriceUpdateResult(Sku::fromTrusted('SKU-1'), 'err')],
        );

        self::assertTrue($result->isPartialSuccess());
    }

    #[Test]
    public function is_not_partial_success_when_all_succeeded(): void
    {
        $result = new PriceUpdateResult(total: 3, succeeded: 3);

        self::assertFalse($result->isPartialSuccess());
    }

    #[Test]
    public function is_not_partial_success_when_none_succeeded(): void
    {
        $result = new PriceUpdateResult(
            total: 3,
            succeeded: 0,
            permanentFailures: [new FailedPriceUpdateResult(Sku::fromTrusted('SKU-1'), 'err')],
        );

        self::assertFalse($result->isPartialSuccess());
    }
}
