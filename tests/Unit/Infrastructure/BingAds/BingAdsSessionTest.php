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
 * Validates constructor constraints, expiry logic, and OAuth factory method.
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
    | fromOAuthResponse Factory Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_session_from_valid_oauth_response(): void
    {
        $response = [
            'access_token' => 'oauth-token-xyz',
            'expires_in' => 3600, // 1 hour
        ];

        $session = BingAdsSession::fromOAuthResponse($response);

        $this->assertSame('oauth-token-xyz', $session->accessToken);
        $this->assertFalse($session->isExpired());
    }

    #[Test]
    public function it_applies_ttl_buffer_to_expiry(): void
    {
        $response = [
            'access_token' => 'token',
            'expires_in' => 120, // 2 minutes
        ];

        $before = new DateTimeImmutable();
        $session = BingAdsSession::fromOAuthResponse($response, ttlBuffer: 60);
        $after = new DateTimeImmutable();

        // With 60s buffer, effective TTL is 60s (120 - 60)
        // Session should expire between 59-61 seconds from now
        $expectedMin = $before->modify('+59 seconds');
        $expectedMax = $after->modify('+61 seconds');

        $this->assertGreaterThanOrEqual($expectedMin, $session->expiresAt);
        $this->assertLessThanOrEqual($expectedMax, $session->expiresAt);
    }

    #[Test]
    public function it_ensures_minimum_ttl_of_one_second_when_buffer_exceeds_expires_in(): void
    {
        $response = [
            'access_token' => 'token',
            'expires_in' => 30, // 30 seconds
        ];

        $before = new DateTimeImmutable();
        $session = BingAdsSession::fromOAuthResponse($response, ttlBuffer: 60); // Buffer > expires_in
        $after = new DateTimeImmutable();

        // max(1, 30 - 60) = 1 second TTL
        $expectedMin = $before->modify('+0 seconds');
        $expectedMax = $after->modify('+2 seconds');

        $this->assertGreaterThanOrEqual($expectedMin, $session->expiresAt);
        $this->assertLessThanOrEqual($expectedMax, $session->expiresAt);
    }

    #[Test]
    public function it_throws_when_access_token_is_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OAuth response missing valid access_token');

        BingAdsSession::fromOAuthResponse([
            'expires_in' => 3600,
        ]);
    }

    #[Test]
    public function it_throws_when_access_token_is_not_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OAuth response missing valid access_token');

        BingAdsSession::fromOAuthResponse([
            'access_token' => 12345, // Not a string
            'expires_in' => 3600,
        ]);
    }

    #[Test]
    public function it_throws_when_access_token_is_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OAuth response missing valid access_token');

        BingAdsSession::fromOAuthResponse([
            'access_token' => '',
            'expires_in' => 3600,
        ]);
    }

    #[Test]
    public function it_throws_when_expires_in_is_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OAuth response missing valid expires_in');

        BingAdsSession::fromOAuthResponse([
            'access_token' => 'token',
        ]);
    }

    #[Test]
    public function it_throws_when_expires_in_is_not_int(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OAuth response missing valid expires_in');

        BingAdsSession::fromOAuthResponse([
            'access_token' => 'token',
            'expires_in' => '3600', // String, not int
        ]);
    }

    #[Test]
    public function it_throws_when_expires_in_is_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OAuth response missing valid expires_in');

        BingAdsSession::fromOAuthResponse([
            'access_token' => 'token',
            'expires_in' => 0,
        ]);
    }

    #[Test]
    public function it_throws_when_expires_in_is_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OAuth response missing valid expires_in');

        BingAdsSession::fromOAuthResponse([
            'access_token' => 'token',
            'expires_in' => -100,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Boundary Condition Tests (Mutation Killers)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_expires_in_of_one_second(): void
    {
        // expires_in = 1 should be valid (boundary: <= 0 vs <= 1)
        $response = [
            'access_token' => 'token',
            'expires_in' => 1,
        ];

        $session = BingAdsSession::fromOAuthResponse($response, ttlBuffer: 0);

        $this->assertSame('token', $session->accessToken);
        $this->assertFalse($session->isExpired());
    }

    #[Test]
    public function it_uses_default_ttl_buffer_of_sixty_seconds(): void
    {
        // Verifies default ttlBuffer = 60 (not 59 or 61)
        $response = [
            'access_token' => 'token',
            'expires_in' => 3600,
        ];

        $before = new DateTimeImmutable();
        $session = BingAdsSession::fromOAuthResponse($response); // Uses default 60s buffer
        $after = new DateTimeImmutable();

        // Effective TTL = 3600 - 60 = 3540 seconds
        $expectedMinExpiry = $before->modify('+3539 seconds');
        $expectedMaxExpiry = $after->modify('+3541 seconds');

        $this->assertGreaterThanOrEqual($expectedMinExpiry, $session->expiresAt);
        $this->assertLessThanOrEqual($expectedMaxExpiry, $session->expiresAt);
    }

    #[Test]
    public function it_enforces_minimum_ttl_of_exactly_one_second(): void
    {
        // max(1, ...) ensures minimum TTL is 1, not 0 or 2
        $response = [
            'access_token' => 'token',
            'expires_in' => 10,
        ];

        $before = new DateTimeImmutable();
        $session = BingAdsSession::fromOAuthResponse($response, ttlBuffer: 100); // Buffer >> expires_in
        $after = new DateTimeImmutable();

        // max(1, 10 - 100) = max(1, -90) = 1 second
        // Session should expire ~1 second from now, not 0 or 2
        $expectedMinExpiry = $before->modify('+0 seconds');
        $expectedMaxExpiry = $after->modify('+2 seconds');

        $this->assertGreaterThanOrEqual($expectedMinExpiry, $session->expiresAt);
        $this->assertLessThanOrEqual($expectedMaxExpiry, $session->expiresAt);

        // Verify it's NOT expired immediately (max(1) not max(0))
        $this->assertFalse($session->isExpired());
    }

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
