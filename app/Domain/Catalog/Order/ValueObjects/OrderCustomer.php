<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Order customer value object.
 *
 * Customer info embedded in order.
 * ID included for cross-referencing with Customer domain.
 * Type is int (0-3) - semantics unknown, preserved as-is.
 *
 * DeviceInfo captured as array to preserve all fields from API
 * (ipAddress, userAgent, awinChannel, facebookBrowserId, facebookClickId, gclid, etc.)
 * since ShopWired doesn't document complete field list.
 *
 * @property array<string, mixed> $deviceInfo
 */
final readonly class OrderCustomer
{
    /**
     * @param array<string, mixed> $deviceInfo Attribution/tracking data (ipAddress, userAgent, awinChannel, etc.)
     */
    public function __construct(
        public int $id,
        public int $type,
        public ?string $dateOfBirth,
        public array $deviceInfo = [],
    ) {
        Assert::greaterThan($id, 0, 'Customer ID must be positive');
    }

    /**
     * Get IP address if available.
     */
    public function ipAddress(): ?string
    {
        $ip = $this->deviceInfo['ipAddress'] ?? null;

        return \is_string($ip) ? $ip : null;
    }

    /**
     * Get user agent if available.
     */
    public function userAgent(): ?string
    {
        $ua = $this->deviceInfo['userAgent'] ?? null;

        return \is_string($ua) ? $ua : null;
    }

    /**
     * Get Awin attribution channel if available.
     */
    public function awinChannel(): ?string
    {
        $channel = $this->deviceInfo['awinChannel'] ?? null;

        return \is_string($channel) ? $channel : null;
    }
}
