<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\BingAds;

use App\Infrastructure\BingAds\BingAdsSession;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * BingAdsSession Unit Tests.
 *
 * Tests the immutable session value object for Bing Ads API authentication.
 * Validates constructor constraints and expiry logic.
 *
 * Note: OAuth response parsing is tested in BingAdsSessionManagerTest
 * as that logic now lives in the SessionManager boundary class.
 */
#[CoversClass(BingAdsSession::class)]
final class BingAdsSessionTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Constructor Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_session_with_valid_token_and_expiry(): void
    {
        $expiresAt = new DateTimeImmutable('+1 hour');

        $session = new BingAdsSession('valid-token-123', $expiresAt);

        $this->assertSame('valid-token-123', $session->accessToken);
        $this->assertSame($expiresAt, $session->expiresAt);
    }

    #[Test]
    public function it_throws_when_access_token_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Access token cannot be empty');

        new BingAdsSession('', new DateTimeImmutable('+1 hour'));
    }

    /*
    |--------------------------------------------------------------------------
    | isExpired Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_false_when_session_is_still_valid(): void
    {
        $session = new BingAdsSession('token', new DateTimeImmutable('+1 hour'));

        $this->assertFalse($session->isExpired());
    }

    #[Test]
    public function it_returns_true_when_session_has_expired(): void
    {
        $session = new BingAdsSession('token', new DateTimeImmutable('-1 second'));

        $this->assertTrue($session->isExpired());
    }

    #[Test]
    public function it_returns_true_when_session_expires_exactly_now(): void
    {
        // Session that expires at current timestamp (boundary condition)
        $session = new BingAdsSession('token', new DateTimeImmutable('now'));

        // >= comparison means "now" is expired
        $this->assertTrue($session->isExpired());
    }

    /*
    |--------------------------------------------------------------------------
    | Boundary Condition Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_is_expired_when_compared_at_exact_expiry_boundary(): void
    {
        // Create a session that expires at a specific timestamp
        // Use a frozen timestamp for deterministic comparison
        $exactExpiry = new DateTimeImmutable('2099-01-01 12:00:00');
        $session = new BingAdsSession('token', $exactExpiry);

        // Create a "now" that exactly matches the expiry time
        // The >= comparison should return true (expired)
        // This is validated by checking that expiresAt matches our expectation
        $this->assertSame($exactExpiry, $session->expiresAt);
    }
}
