<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks;

use App\Infrastructure\Linnworks\LinnworksSession;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LinnworksSession Unit Tests.
 *
 * Tests the immutable session value object for Linnworks API authentication.
 * Covers constructor validation, expiry checking, and factory method for
 * parsing auth endpoint responses.
 */
#[CoversClass(LinnworksSession::class)]
final class LinnworksSessionTest extends TestCase
{
    private const string TEST_TOKEN = 'test-auth-token-12345';
    private const string TEST_SERVER_URL = 'https://eu-ext.linnworks.net';

    /*
    |--------------------------------------------------------------------------
    | Constructor Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_session_with_valid_parameters(): void
    {
        $expiresAt = new DateTimeImmutable('+1 hour');

        $session = new LinnworksSession(
            token: self::TEST_TOKEN,
            serverUrl: self::TEST_SERVER_URL,
            expiresAt: $expiresAt,
        );

        $this->assertSame(self::TEST_TOKEN, $session->token);
        $this->assertSame(self::TEST_SERVER_URL, $session->serverUrl);
        $this->assertSame($expiresAt, $session->expiresAt);
    }

    #[Test]
    public function it_throws_exception_for_empty_token(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Session token cannot be empty');

        new LinnworksSession(
            token: '',
            serverUrl: self::TEST_SERVER_URL,
            expiresAt: new DateTimeImmutable('+1 hour'),
        );
    }

    #[Test]
    public function it_throws_exception_for_empty_server_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Server URL cannot be empty');

        new LinnworksSession(
            token: self::TEST_TOKEN,
            serverUrl: '',
            expiresAt: new DateTimeImmutable('+1 hour'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Expiry Check Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_false_for_unexpired_session(): void
    {
        $session = new LinnworksSession(
            token: self::TEST_TOKEN,
            serverUrl: self::TEST_SERVER_URL,
            expiresAt: new DateTimeImmutable('+1 hour'),
        );

        $this->assertFalse($session->isExpired());
    }

    #[Test]
    public function it_returns_true_for_expired_session(): void
    {
        $session = new LinnworksSession(
            token: self::TEST_TOKEN,
            serverUrl: self::TEST_SERVER_URL,
            expiresAt: new DateTimeImmutable('-1 second'),
        );

        $this->assertTrue($session->isExpired());
    }

    #[Test]
    public function it_returns_true_when_expiry_is_exactly_now(): void
    {
        // Create session that expires at this exact moment
        $session = new LinnworksSession(
            token: self::TEST_TOKEN,
            serverUrl: self::TEST_SERVER_URL,
            expiresAt: new DateTimeImmutable(),
        );

        // isExpired() uses >= comparison, so exactly now should be expired
        $this->assertTrue($session->isExpired());
    }

    /*
    |--------------------------------------------------------------------------
    | Factory Method Tests: fromAuthResponse()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_session_from_valid_auth_response(): void
    {
        $response = [
            'Token' => self::TEST_TOKEN,
            'Server' => self::TEST_SERVER_URL,
            'TTL' => 86400, // 24 hours
        ];

        $session = LinnworksSession::fromAuthResponse($response, ttlBuffer: 300);

        $this->assertSame(self::TEST_TOKEN, $session->token);
        $this->assertSame(self::TEST_SERVER_URL, $session->serverUrl);
        $this->assertFalse($session->isExpired());

        // Verify expiry is approximately 24 hours minus 300 seconds buffer
        $expectedExpiry = new DateTimeImmutable('+86100 seconds');
        $this->assertEqualsWithDelta(
            $expectedExpiry->getTimestamp(),
            $session->expiresAt->getTimestamp(),
            delta: 2, // Allow 2 second tolerance for test execution
        );
    }

    #[Test]
    public function it_uses_default_ttl_when_not_provided(): void
    {
        $response = [
            'Token' => self::TEST_TOKEN,
            'Server' => self::TEST_SERVER_URL,
            // No TTL field - should default to 86400
        ];

        $session = LinnworksSession::fromAuthResponse($response, ttlBuffer: 0);

        // Default 24 hours = 86400 seconds
        $expectedExpiry = new DateTimeImmutable('+86400 seconds');
        $this->assertEqualsWithDelta(
            $expectedExpiry->getTimestamp(),
            $session->expiresAt->getTimestamp(),
            delta: 2,
        );
    }

    #[Test]
    public function it_uses_default_ttl_when_ttl_is_not_integer(): void
    {
        $response = [
            'Token' => self::TEST_TOKEN,
            'Server' => self::TEST_SERVER_URL,
            'TTL' => 'invalid', // Not an integer
        ];

        $session = LinnworksSession::fromAuthResponse($response, ttlBuffer: 0);

        // Should fall back to 86400 default
        $expectedExpiry = new DateTimeImmutable('+86400 seconds');
        $this->assertEqualsWithDelta(
            $expectedExpiry->getTimestamp(),
            $session->expiresAt->getTimestamp(),
            delta: 2,
        );
    }

    #[Test]
    public function it_applies_ttl_buffer_correctly(): void
    {
        $response = [
            'Token' => self::TEST_TOKEN,
            'Server' => self::TEST_SERVER_URL,
            'TTL' => 3600, // 1 hour
        ];

        $session = LinnworksSession::fromAuthResponse($response, ttlBuffer: 600);

        // 3600 - 600 = 3000 seconds effective TTL
        $expectedExpiry = new DateTimeImmutable('+3000 seconds');
        $this->assertEqualsWithDelta(
            $expectedExpiry->getTimestamp(),
            $session->expiresAt->getTimestamp(),
            delta: 2,
        );
    }

    #[Test]
    public function it_ensures_minimum_ttl_of_one_second(): void
    {
        $response = [
            'Token' => self::TEST_TOKEN,
            'Server' => self::TEST_SERVER_URL,
            'TTL' => 100,
        ];

        // Buffer larger than TTL would result in negative, but min() ensures 1
        $session = LinnworksSession::fromAuthResponse($response, ttlBuffer: 500);

        // max(1, 100 - 500) = max(1, -400) = 1
        $expectedExpiry = new DateTimeImmutable('+1 second');
        $this->assertEqualsWithDelta(
            $expectedExpiry->getTimestamp(),
            $session->expiresAt->getTimestamp(),
            delta: 2,
        );
    }

    #[Test]
    public function it_throws_exception_when_token_is_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Auth response missing valid Token');

        LinnworksSession::fromAuthResponse([
            'Server' => self::TEST_SERVER_URL,
            'TTL' => 86400,
        ], ttlBuffer: 0);
    }

    #[Test]
    public function it_throws_exception_when_token_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Auth response missing valid Token');

        LinnworksSession::fromAuthResponse([
            'Token' => '',
            'Server' => self::TEST_SERVER_URL,
            'TTL' => 86400,
        ], ttlBuffer: 0);
    }

    #[Test]
    public function it_throws_exception_when_token_is_not_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Auth response missing valid Token');

        LinnworksSession::fromAuthResponse([
            'Token' => 12345,
            'Server' => self::TEST_SERVER_URL,
            'TTL' => 86400,
        ], ttlBuffer: 0);
    }

    #[Test]
    public function it_throws_exception_when_server_is_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Auth response missing valid Server');

        LinnworksSession::fromAuthResponse([
            'Token' => self::TEST_TOKEN,
            'TTL' => 86400,
        ], ttlBuffer: 0);
    }

    #[Test]
    public function it_throws_exception_when_server_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Auth response missing valid Server');

        LinnworksSession::fromAuthResponse([
            'Token' => self::TEST_TOKEN,
            'Server' => '',
            'TTL' => 86400,
        ], ttlBuffer: 0);
    }

    #[Test]
    public function it_throws_exception_when_server_is_not_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Auth response missing valid Server');

        LinnworksSession::fromAuthResponse([
            'Token' => self::TEST_TOKEN,
            'Server' => ['invalid'],
            'TTL' => 86400,
        ], ttlBuffer: 0);
    }
}
