<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired;

use App\Infrastructure\Shopwired\RetryStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RetryStrategy Enum Unit Tests.
 *
 * Tests the retry strategy configuration for ShopWired API:
 * - Background: Patient retries for queue jobs (5 attempts, exponential backoff)
 * - Urgent: Fast-fail for user-facing requests (2 attempts, fixed delay)
 */
#[CoversClass(RetryStrategy::class)]
final class RetryStrategyTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Background Strategy Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function background_strategy_has_five_retry_attempts(): void
    {
        self::assertSame(5, RetryStrategy::Background->times());
    }

    #[Test]
    public function background_strategy_has_500ms_base_delay(): void
    {
        self::assertSame(500, RetryStrategy::Background->baseDelayMs());
    }

    #[Test]
    public function background_strategy_uses_exponential_backoff(): void
    {
        self::assertTrue(RetryStrategy::Background->useExponentialBackoff());
    }

    #[Test]
    public function background_strategy_has_correct_string_value(): void
    {
        self::assertSame('background', RetryStrategy::Background->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Urgent Strategy Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function urgent_strategy_has_two_retry_attempts(): void
    {
        self::assertSame(2, RetryStrategy::Urgent->times());
    }

    #[Test]
    public function urgent_strategy_has_100ms_base_delay(): void
    {
        self::assertSame(100, RetryStrategy::Urgent->baseDelayMs());
    }

    #[Test]
    public function urgent_strategy_uses_fixed_delay(): void
    {
        self::assertFalse(RetryStrategy::Urgent->useExponentialBackoff());
    }

    #[Test]
    public function urgent_strategy_has_correct_string_value(): void
    {
        self::assertSame('urgent', RetryStrategy::Urgent->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Strategy Comparison Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function background_has_more_retries_than_urgent(): void
    {
        self::assertGreaterThan(
            RetryStrategy::Urgent->times(),
            RetryStrategy::Background->times(),
        );
    }

    #[Test]
    public function background_has_longer_base_delay_than_urgent(): void
    {
        self::assertGreaterThan(
            RetryStrategy::Urgent->baseDelayMs(),
            RetryStrategy::Background->baseDelayMs(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Enum Completeness Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('allStrategiesProvider')]
    public function all_strategies_return_positive_times(RetryStrategy $strategy): void
    {
        self::assertGreaterThan(0, $strategy->times());
    }

    #[Test]
    #[DataProvider('allStrategiesProvider')]
    public function all_strategies_return_positive_base_delay(RetryStrategy $strategy): void
    {
        self::assertGreaterThan(0, $strategy->baseDelayMs());
    }

    /**
     * @return array<string, array{RetryStrategy}>
     */
    public static function allStrategiesProvider(): array
    {
        return [
            'Background' => [RetryStrategy::Background],
            'Urgent' => [RetryStrategy::Urgent],
        ];
    }
}
