<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

/**
 * RFC 7231 compliant Retry-After header parser.
 *
 * The Retry-After header can be either:
 * - delta-seconds: Non-negative integer (e.g., "120")
 * - HTTP-date: RFC 7231 date format (e.g., "Wed, 21 Oct 2015 07:28:00 GMT")
 *
 * This utility safely parses both formats and returns the delay in seconds,
 * or null if the header is invalid/missing.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7231#section-7.1.3
 * @template-pattern API Client Support Utility
 */
final class RetryAfterParser
{
    /**
     * Parse Retry-After header value to seconds.
     *
     * @param string|null $headerValue Raw header value from HTTP response
     * @param int|null $maxSeconds Maximum allowed seconds (prevents unreasonable waits)
     *
     * @return int|null Seconds to wait, or null if invalid/missing
     */
    public static function parse(?string $headerValue, ?int $maxSeconds = 300): ?int
    {
        if (($headerValue === null) || ($headerValue === '')) {
            return null;
        }

        // Try numeric format first (most common for APIs)
        if (\is_numeric($headerValue)) {
            $seconds = (int) $headerValue;

            if ($seconds <= 0) {
                return null;
            }

            // Cap to maximum if specified
            if (($maxSeconds !== null) && ($seconds > $maxSeconds)) {
                return $maxSeconds;
            }

            return $seconds;
        }

        // Try HTTP-date format (RFC 7231)
        $timestamp = \strtotime($headerValue);

        if ($timestamp === false) {
            return null;
        }

        $seconds = $timestamp - \time();

        // Date in the past or invalid
        if ($seconds <= 0) {
            return null;
        }

        // Cap to maximum if specified
        if (($maxSeconds !== null) && ($seconds > $maxSeconds)) {
            return $maxSeconds;
        }

        return $seconds;
    }
}
