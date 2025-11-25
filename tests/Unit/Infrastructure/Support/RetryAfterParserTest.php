<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Support;

use App\Infrastructure\Support\RetryAfterParser;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RetryAfterParser Unit Tests.
 *
 * Tests RFC 7231 compliant Retry-After header parsing.
 * Covers both delta-seconds (numeric) and HTTP-date formats,
 * including boundary conditions and capping logic.
 */
#[CoversClass(RetryAfterParser::class)]
final class RetryAfterParserTest extends TestCase
{
    // Note: HTTP-date tests use real time because RetryAfterParser uses native
    // PHP time() which isn't affected by Carbon::setTestNow(). Tests are designed
    // to be resilient to timing by using appropriate deltas.

    /*
    |--------------------------------------------------------------------------
    | Invalid Input Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('invalidHeaderValuesProvider')]
    public function it_returns_null_for_invalid_null_or_empty_header_values(?string $headerValue): void
    {
        $this->assertNull(RetryAfterParser::parse($headerValue));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function invalidHeaderValuesProvider(): array
    {
        return [
            'null value' => [null],
            'empty string' => [''],
            'whitespace string' => ['   '],
            'non-numeric, non-date string' => ['not-a-valid-header'],
            'completely unparseable string' => ['xyzzy-gibberish-999'],
            'malformed date format' => ['2023-10-26 12:00:00'], // Not RFC 7231 format
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Numeric (Delta-Seconds) Format Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('validNumericValuesProvider')]
    public function it_correctly_handles_numeric_delta_seconds_values(string $headerValue, int $expectedSeconds): void
    {
        $this->assertSame($expectedSeconds, RetryAfterParser::parse($headerValue));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function validNumericValuesProvider(): array
    {
        return [
            'standard value' => ['60', 60],
            'large value within default cap' => ['250', 250],
            'minimum valid value' => ['1', 1],
            'float-like string truncates to integer' => ['120.7', 120],
        ];
    }

    #[Test]
    #[DataProvider('zeroOrNegativeValuesProvider')]
    public function it_returns_null_for_zero_or_negative_numeric_values(string $headerValue): void
    {
        $this->assertNull(RetryAfterParser::parse($headerValue));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function zeroOrNegativeValuesProvider(): array
    {
        return [
            'zero' => ['0'],
            'negative one' => ['-1'],
            'large negative' => ['-120'],
        ];
    }

    #[Test]
    public function it_caps_numeric_values_that_exceed_the_max_seconds_limit(): void
    {
        $maxSeconds = 60;

        // Value greater than cap returns cap
        $this->assertSame($maxSeconds, RetryAfterParser::parse('120', $maxSeconds));

        // Value exactly at cap returns the value (not capped, since > not >=)
        $this->assertSame(60, RetryAfterParser::parse('60', $maxSeconds));

        // Value 1 over cap returns cap (boundary test for > vs >=)
        $this->assertSame($maxSeconds, RetryAfterParser::parse('61', $maxSeconds));

        // Value below cap returns actual value
        $this->assertSame(30, RetryAfterParser::parse('30', $maxSeconds));
    }

    #[Test]
    public function it_uses_default_max_seconds_of_300(): void
    {
        // Value exceeding default cap (300) gets capped
        $this->assertSame(300, RetryAfterParser::parse('5000'));

        // Value at exactly default cap returns the value
        $this->assertSame(300, RetryAfterParser::parse('300'));

        // Value 1 over default cap returns cap
        $this->assertSame(300, RetryAfterParser::parse('301'));

        // Value below default cap returns actual value
        $this->assertSame(299, RetryAfterParser::parse('299'));
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP-Date Format Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_correctly_handles_valid_future_http_date_values(): void
    {
        // Use real time since RetryAfterParser uses native PHP time()
        $now = Carbon::now();

        // 120 seconds in the future (buffer for test execution time)
        $date120s = $now->copy()->addSeconds(120)->toRfc7231String();
        $result = RetryAfterParser::parse($date120s);
        // Allow 5 second tolerance for test execution time
        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual(115, $result);
        $this->assertLessThanOrEqual(120, $result);

        // 1 hour in the future - gets capped to default max (300)
        $date1h = $now->copy()->addHour()->toRfc7231String();
        $result1h = RetryAfterParser::parse($date1h);
        $this->assertNotNull($result1h);
        $this->assertSame(300, $result1h); // Capped to default maxSeconds
    }

    #[Test]
    public function it_returns_null_for_past_or_present_http_date_values(): void
    {
        // Use real time since RetryAfterParser uses native PHP time()
        $now = Carbon::now();

        // 1 second in the past
        $past1s = $now->copy()->subSecond()->toRfc7231String();
        $this->assertNull(RetryAfterParser::parse($past1s));

        // 1 hour in the past
        $past1h = $now->copy()->subHour()->toRfc7231String();
        $this->assertNull(RetryAfterParser::parse($past1h));
    }

    #[Test]
    public function it_caps_http_date_values_that_exceed_the_max_seconds_limit(): void
    {
        // Use real time since RetryAfterParser uses native PHP time()
        $now = Carbon::now();
        $maxSeconds = 60;

        // Date resolving to delay greater than cap returns cap
        $farFutureDate = $now->copy()->addSeconds(120)->toRfc7231String();
        $this->assertSame($maxSeconds, RetryAfterParser::parse($farFutureDate, $maxSeconds));

        // Date resolving to exactly 1 over cap returns cap (boundary test for > vs >=)
        $oneOverDate = $now->copy()->addSeconds(61)->toRfc7231String();
        $this->assertSame($maxSeconds, RetryAfterParser::parse($oneOverDate, $maxSeconds));

        // Date resolving to exactly at cap returns the value (not capped)
        $atCapDate = $now->copy()->addSeconds(60)->toRfc7231String();
        $result = RetryAfterParser::parse($atCapDate, $maxSeconds);
        // Allow 1 second tolerance for test execution, but must be <= maxSeconds
        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual(59, $result);
        $this->assertLessThanOrEqual(60, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | Capping Disabled Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_does_not_cap_values_when_max_seconds_is_null(): void
    {
        $largeDelay = 9999;

        // Numeric string without cap
        $this->assertSame($largeDelay, RetryAfterParser::parse((string) $largeDelay, null));

        // HTTP-date without cap (use real time)
        $now = Carbon::now();
        $futureDate = $now->copy()->addSeconds($largeDelay)->toRfc7231String();
        $result = RetryAfterParser::parse($futureDate, null);
        // Allow 5 second tolerance for test execution time
        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual($largeDelay - 5, $result);
        $this->assertLessThanOrEqual($largeDelay, $result);
    }
}
