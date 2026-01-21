<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\ValueObjects\OrderAnalyticsHashMatcher;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OrderAnalyticsHashMatcher Value Object Tests.
 *
 * Tests multi-algorithm hash matching for Mixpanel order deduplication.
 * Critical for detecting orders tracked with different hash variations:
 * - SHA-256 vs Legacy Base64 algorithm (browser capability)
 * - Configured salt vs fallback salt (frontend bug)
 */
#[CoversClass(OrderAnalyticsHashMatcher::class)]
final class OrderAnalyticsHashMatcherTest extends TestCase
{
    private const string TEST_SALT = 'MINZM+G8mVxffMb4uHnQAnSn4pSxBsDum9Q96QqlHpQ=';

    private const int TEST_REFERENCE = 111392;

    /*
    |--------------------------------------------------------------------------
    | existsInHashes: SHA-256 Algorithm Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_matches_sha256_with_configured_salt(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');

        // Generate expected hash: SHA-256(reference + configured_salt)
        $expectedHash = \hash('sha256', self::TEST_REFERENCE . self::TEST_SALT);

        $hashSet = \array_flip([$expectedHash]);

        $exists = OrderAnalyticsHashMatcher::existsInHashes(
            $hashSet,
            self::TEST_REFERENCE,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        $this->assertTrue($exists, 'Should match SHA-256 hash with configured salt');
    }

    #[Test]
    public function it_matches_sha256_with_fallback_salt(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');
        $timestamp = $orderPlacedAt->getTimestamp();

        // Generate expected hash: SHA-256(reference + fallback_salt)
        $fallbackSalt = 'alz-' . $timestamp;
        $expectedHash = \hash('sha256', self::TEST_REFERENCE . $fallbackSalt);

        $hashSet = \array_flip([$expectedHash]);

        $exists = OrderAnalyticsHashMatcher::existsInHashes(
            $hashSet,
            self::TEST_REFERENCE,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        $this->assertTrue($exists, 'Should match SHA-256 hash with fallback salt (frontend bug)');
    }

    /*
    |--------------------------------------------------------------------------
    | existsInHashes: Legacy Base64 Algorithm Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_matches_legacy_base64_with_configured_salt(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');

        // Generate expected hash: Base64(reference + configured_salt), stripped, first 32 chars
        $input = self::TEST_REFERENCE . self::TEST_SALT;
        $base64 = \base64_encode($input);
        $stripped = \preg_replace('/[^a-zA-Z0-9]/', '', $base64);
        $expectedHash = \mb_substr($stripped, 0, 32);

        $hashSet = \array_flip([$expectedHash]);

        $exists = OrderAnalyticsHashMatcher::existsInHashes(
            $hashSet,
            self::TEST_REFERENCE,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        $this->assertTrue($exists, 'Should match Legacy Base64 hash with configured salt (old browser)');
    }

    #[Test]
    public function it_matches_legacy_base64_with_fallback_salt(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');
        $timestamp = $orderPlacedAt->getTimestamp();

        // Generate expected hash: Base64(reference + fallback_salt), stripped, first 32 chars
        $fallbackSalt = 'alz-' . $timestamp;
        $input = self::TEST_REFERENCE . $fallbackSalt;
        $base64 = \base64_encode($input);
        $stripped = \preg_replace('/[^a-zA-Z0-9]/', '', $base64);
        $expectedHash = \mb_substr($stripped, 0, 32);

        $hashSet = \array_flip([$expectedHash]);

        $exists = OrderAnalyticsHashMatcher::existsInHashes(
            $hashSet,
            self::TEST_REFERENCE,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        $this->assertTrue($exists, 'Should match Legacy Base64 hash with fallback salt (old browser + bug)');
    }

    /*
    |--------------------------------------------------------------------------
    | existsInHashes: No Match Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_false_when_no_match(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');

        // Hash set with unrelated hashes
        $hashSet = \array_flip([
            'completely_different_hash_that_will_not_match',
            'another_unrelated_hash_value_here_for_testing',
        ]);

        $exists = OrderAnalyticsHashMatcher::existsInHashes(
            $hashSet,
            self::TEST_REFERENCE,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        $this->assertFalse($exists, 'Should return false when no hash variation matches');
    }

    #[Test]
    public function it_returns_false_for_empty_hash_set(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');

        $exists = OrderAnalyticsHashMatcher::existsInHashes(
            [],
            self::TEST_REFERENCE,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        $this->assertFalse($exists, 'Should return false for empty hash set');
    }

    #[Test]
    public function it_returns_false_for_different_order_reference(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');

        // Hash for reference 111392
        $expectedHash = \hash('sha256', '111392' . self::TEST_SALT);
        $hashSet = \array_flip([$expectedHash]);

        // Check for different reference 111393
        $exists = OrderAnalyticsHashMatcher::existsInHashes(
            $hashSet,
            111393,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        $this->assertFalse($exists, 'Should not match hash for different order reference');
    }

    /*
    |--------------------------------------------------------------------------
    | generateCandidateHashes Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_generates_exactly_four_candidate_hashes(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');

        $candidates = OrderAnalyticsHashMatcher::generateCandidateHashes(
            self::TEST_REFERENCE,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        $this->assertCount(4, $candidates, 'Should generate exactly 4 candidate hashes');
    }

    #[Test]
    public function it_generates_unique_candidate_hashes(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');

        $candidates = OrderAnalyticsHashMatcher::generateCandidateHashes(
            self::TEST_REFERENCE,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        $uniqueCandidates = \array_unique($candidates);

        $this->assertCount(4, $uniqueCandidates, 'All 4 candidate hashes should be unique');
    }

    #[Test]
    public function it_generates_deterministic_hashes(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');

        $candidates1 = OrderAnalyticsHashMatcher::generateCandidateHashes(
            self::TEST_REFERENCE,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        $candidates2 = OrderAnalyticsHashMatcher::generateCandidateHashes(
            self::TEST_REFERENCE,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        $this->assertSame($candidates1, $candidates2, 'Same inputs should produce same candidate hashes');
    }

    #[Test]
    public function it_generates_sha256_hashes_as_64_char_hex(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');

        $candidates = OrderAnalyticsHashMatcher::generateCandidateHashes(
            self::TEST_REFERENCE,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        // First two candidates are SHA-256 (64-char lowercase hex)
        $this->assertSame(64, \mb_strlen($candidates[0]), 'SHA-256 with configured salt should be 64 chars');
        $this->assertSame(64, \mb_strlen($candidates[1]), 'SHA-256 with fallback salt should be 64 chars');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $candidates[0]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $candidates[1]);
    }

    #[Test]
    public function it_generates_legacy_base64_hashes_as_alphanumeric(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');

        $candidates = OrderAnalyticsHashMatcher::generateCandidateHashes(
            self::TEST_REFERENCE,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        // Last two candidates are Legacy Base64 (up to 32 alphanumeric chars)
        $this->assertLessThanOrEqual(32, \mb_strlen($candidates[2]), 'Legacy Base64 with configured salt should be ≤32 chars');
        $this->assertLessThanOrEqual(32, \mb_strlen($candidates[3]), 'Legacy Base64 with fallback salt should be ≤32 chars');
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $candidates[2]);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $candidates[3]);
    }

    /*
    |--------------------------------------------------------------------------
    | Fallback Salt Tests (Critical: Seconds vs Milliseconds)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_seconds_not_milliseconds_for_fallback_salt(): void
    {
        // Specific timestamp: 2026-01-21 09:28:18 UTC
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:28:18 UTC');
        $expectedTimestamp = $orderPlacedAt->getTimestamp();

        // Verify timestamp is reasonable (10 digits = seconds, 13 digits = milliseconds)
        $this->assertSame(10, \mb_strlen((string) $expectedTimestamp), 'Unix timestamp should be 10 digits (seconds)');

        // Generate hash with fallback salt using SECONDS
        $fallbackSalt = 'alz-' . $expectedTimestamp;
        $expectedHash = \hash('sha256', self::TEST_REFERENCE . $fallbackSalt);

        $hashSet = \array_flip([$expectedHash]);

        $exists = OrderAnalyticsHashMatcher::existsInHashes(
            $hashSet,
            self::TEST_REFERENCE,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        $this->assertTrue($exists, 'Fallback salt should use seconds (10-digit Unix timestamp)');

        // Verify milliseconds would NOT match
        $millisecondsSalt = 'alz-' . ($expectedTimestamp * 1000);
        $wrongHash = \hash('sha256', self::TEST_REFERENCE . $millisecondsSalt);

        $wrongHashSet = \array_flip([$wrongHash]);

        $existsWithWrongHash = OrderAnalyticsHashMatcher::existsInHashes(
            $wrongHashSet,
            self::TEST_REFERENCE,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        $this->assertFalse($existsWithWrongHash, 'Milliseconds-based hash should NOT match');
    }

    #[Test]
    public function it_generates_correct_fallback_salt_format(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');
        $timestamp = $orderPlacedAt->getTimestamp();

        $candidates = OrderAnalyticsHashMatcher::generateCandidateHashes(
            self::TEST_REFERENCE,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        // SHA-256 with fallback salt (second candidate)
        $expectedFallbackHash = \hash('sha256', self::TEST_REFERENCE . 'alz-' . $timestamp);

        $this->assertSame($expectedFallbackHash, $candidates[1], 'Fallback salt format should be "alz-{timestamp}"');
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_rejects_zero_reference(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order reference must be positive');

        OrderAnalyticsHashMatcher::generateCandidateHashes(0, $orderPlacedAt, self::TEST_SALT);
    }

    #[Test]
    public function it_rejects_negative_reference(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order reference must be positive');

        OrderAnalyticsHashMatcher::generateCandidateHashes(-1, $orderPlacedAt, self::TEST_SALT);
    }

    #[Test]
    public function it_rejects_empty_salt(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Analytics salt cannot be empty');

        OrderAnalyticsHashMatcher::generateCandidateHashes(self::TEST_REFERENCE, $orderPlacedAt, '');
    }

    /*
    |--------------------------------------------------------------------------
    | Edge Cases
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_reference_of_one(): void
    {
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');

        $expectedHash = \hash('sha256', '1' . self::TEST_SALT);
        $hashSet = \array_flip([$expectedHash]);

        $exists = OrderAnalyticsHashMatcher::existsInHashes(
            $hashSet,
            1,
            $orderPlacedAt,
            self::TEST_SALT,
        );

        $this->assertTrue($exists, 'Should accept reference=1 (boundary value)');
    }

    #[Test]
    public function it_handles_different_order_dates_correctly(): void
    {
        // Two orders with same reference but different dates
        $orderPlacedAt1 = new DateTimeImmutable('2026-01-21 09:00:00 UTC');
        $orderPlacedAt2 = new DateTimeImmutable('2026-01-22 10:00:00 UTC');

        // Generate hash using first order's fallback salt
        $fallbackSalt1 = 'alz-' . $orderPlacedAt1->getTimestamp();
        $hash1 = \hash('sha256', self::TEST_REFERENCE . $fallbackSalt1);
        $hashSet = \array_flip([$hash1]);

        // First order should match
        $exists1 = OrderAnalyticsHashMatcher::existsInHashes(
            $hashSet,
            self::TEST_REFERENCE,
            $orderPlacedAt1,
            self::TEST_SALT,
        );
        $this->assertTrue($exists1, 'Order with matching date should match');

        // Second order (different date) should NOT match (only via fallback salt)
        // because the fallback salt uses a different timestamp
        $exists2 = OrderAnalyticsHashMatcher::existsInHashes(
            $hashSet,
            self::TEST_REFERENCE,
            $orderPlacedAt2,
            self::TEST_SALT,
        );
        $this->assertFalse($exists2, 'Order with different date should not match fallback salt hash');
    }

    #[Test]
    public function it_handles_legacy_base64_with_short_input(): void
    {
        // Short input produces Base64 shorter than 32 chars after stripping
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');
        $shortReference = 1;
        $shortSalt = 'ab';

        $candidates = OrderAnalyticsHashMatcher::generateCandidateHashes(
            $shortReference,
            $orderPlacedAt,
            $shortSalt,
        );

        // Legacy Base64 candidates should be valid even if short
        $this->assertGreaterThan(0, \mb_strlen($candidates[2]), 'Legacy Base64 should handle short input');
        $this->assertLessThanOrEqual(32, \mb_strlen($candidates[2]), 'Legacy Base64 should be ≤32 chars');
    }
}
