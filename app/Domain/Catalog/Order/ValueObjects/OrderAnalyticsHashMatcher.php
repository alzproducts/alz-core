<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * Multi-algorithm hash matcher for order analytics deduplication.
 *
 * The frontend tracking had two sources of hash variation:
 *
 * 1. **Salt bug**: Frontend sometimes used fallback salt "alz-{timestamp}" instead
 *    of the configured salt when window.ALZ_CONFIG wasn't available.
 *
 * 2. **Algorithm fallback**: Older browsers without crypto.subtle use a Base64-based
 *    hash instead of SHA-256.
 *
 * This matcher generates all 4 possible hash combinations (2 salts × 2 algorithms)
 * and checks if any match exists in the provided hash set.
 *
 * Hash variations:
 * - SHA-256 + configured salt (correct path)
 * - SHA-256 + fallback salt (buggy salt, modern browser)
 * - Legacy Base64 + configured salt (old browser, correct salt)
 * - Legacy Base64 + fallback salt (old browser, buggy salt)
 *
 * @see Frontend: shopwired-theme/assets/js/utils/data/checkoutPageData.js
 */
final readonly class OrderAnalyticsHashMatcher
{
    /**
     * Check if order hash exists under any known variation.
     *
     * @param array<string, int|string> $existingHashSet Hash set for O(1) lookup (via array_flip)
     * @param int $orderReference Order reference number
     * @param DateTimeImmutable $orderPlacedAt Order placement timestamp (for fallback salt)
     * @param string $configuredSalt The configured analytics salt
     */
    public static function existsInHashes(
        array $existingHashSet,
        int $orderReference,
        DateTimeImmutable $orderPlacedAt,
        string $configuredSalt,
    ): bool {
        $candidates = self::generateCandidateHashes($orderReference, $orderPlacedAt, $configuredSalt);

        foreach ($candidates as $hash) {
            if (\array_key_exists($hash, $existingHashSet)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate all 4 candidate hashes for an order.
     *
     * @param int $orderReference Order reference number
     * @param DateTimeImmutable $orderPlacedAt Order placement timestamp (for fallback salt)
     * @param string $configuredSalt The configured analytics salt
     *
     * @return list<string> All possible hash values (4 total)
     */
    public static function generateCandidateHashes(
        int $orderReference,
        DateTimeImmutable $orderPlacedAt,
        string $configuredSalt,
    ): array {
        Assert::greaterThan($orderReference, 0, 'Order reference must be positive');
        Assert::notEmpty($configuredSalt, 'Analytics salt cannot be empty');

        $fallbackSalt = self::buildFallbackSalt($orderPlacedAt);
        $referenceStr = (string) $orderReference;

        return [
            // 1. SHA-256 + configured salt (correct path)
            self::sha256Hash($referenceStr . $configuredSalt),
            // 2. SHA-256 + fallback salt (buggy salt, modern browser)
            self::sha256Hash($referenceStr . $fallbackSalt),
            // 3. Legacy Base64 + configured salt (old browser, correct salt)
            self::legacyBase64Hash($referenceStr . $configuredSalt),
            // 4. Legacy Base64 + fallback salt (old browser, buggy salt)
            self::legacyBase64Hash($referenceStr . $fallbackSalt),
        ];
    }

    /**
     * SHA-256 hash (64-char lowercase hex).
     *
     * Matches frontend: await crypto.subtle.digest('SHA-256', data)
     */
    private static function sha256Hash(string $input): string
    {
        return \hash('sha256', $input);
    }

    /**
     * Legacy Base64 hash (up to 32-char alphanumeric).
     *
     * Matches frontend fallback for browsers without crypto.subtle:
     * btoa(text).replace(/[^a-zA-Z0-9]/g, "").substring(0, 32)
     */
    private static function legacyBase64Hash(string $input): string
    {
        $base64 = \base64_encode($input);
        $stripped = \preg_replace('/[^a-zA-Z0-9]/', '', $base64);

        // preg_replace can return null on error, but our pattern is safe
        Assert::string($stripped);

        return \mb_substr($stripped, 0, 32);
    }

    /**
     * Build fallback salt: "alz-" + Unix timestamp (SECONDS).
     *
     * CRITICAL: Uses getTimestamp() which returns seconds, NOT milliseconds.
     * Frontend Twig used: date('U') which also returns Unix seconds.
     */
    private static function buildFallbackSalt(DateTimeImmutable $orderPlacedAt): string
    {
        return 'alz-' . $orderPlacedAt->getTimestamp();
    }
}
