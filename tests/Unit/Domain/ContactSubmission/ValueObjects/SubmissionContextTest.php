<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ContactSubmission\ValueObjects;

use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SubmissionContext Value Object Unit Tests.
 *
 * Tests the request context captured at form submission.
 * IP address is the only required field (server-side captured).
 */
#[CoversClass(SubmissionContext::class)]
final class SubmissionContextTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_with_required_fields(): void
    {
        $timestamp = new DateTimeImmutable('2024-01-15 10:30:00');

        $context = new SubmissionContext(
            clientTimestamp: $timestamp,
            ipAddress: '192.168.1.1',
        );

        self::assertSame($timestamp, $context->clientTimestamp);
        self::assertSame('192.168.1.1', $context->ipAddress);
        self::assertNull($context->pageUrl);
        self::assertNull($context->referrerUrl);
        self::assertNull($context->userAgent);
    }

    #[Test]
    public function it_creates_with_all_fields(): void
    {
        $timestamp = new DateTimeImmutable('2024-01-15 10:30:00');

        $context = new SubmissionContext(
            clientTimestamp: $timestamp,
            ipAddress: '203.0.113.50',
            pageUrl: 'https://alzproducts.co.uk/contact',
            referrerUrl: 'https://google.com/search?q=mobility+aids',
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
        );

        self::assertSame($timestamp, $context->clientTimestamp);
        self::assertSame('203.0.113.50', $context->ipAddress);
        self::assertSame('https://alzproducts.co.uk/contact', $context->pageUrl);
        self::assertSame('https://google.com/search?q=mobility+aids', $context->referrerUrl);
        self::assertSame('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0', $context->userAgent);
    }

    /*
    |--------------------------------------------------------------------------
    | IP Address Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_for_empty_ip_address(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IP address is required');

        new SubmissionContext(
            clientTimestamp: new DateTimeImmutable(),
            ipAddress: '',
        );
    }

    #[Test]
    public function it_accepts_whitespace_ip_address(): void
    {
        // Note: Assert::notEmpty() only checks for empty string, not whitespace
        // Server-side IP capture ensures valid IP; domain accepts for resilience
        $context = new SubmissionContext(
            clientTimestamp: new DateTimeImmutable(),
            ipAddress: '   ',
        );

        self::assertSame('   ', $context->ipAddress);
    }

    #[Test]
    public function it_accepts_ipv6_address(): void
    {
        $context = new SubmissionContext(
            clientTimestamp: new DateTimeImmutable(),
            ipAddress: '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
        );

        self::assertSame('2001:0db8:85a3:0000:0000:8a2e:0370:7334', $context->ipAddress);
    }
}
