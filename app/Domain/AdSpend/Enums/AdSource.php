<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\Enums;

/**
 * Represents the source/network of advertising campaigns.
 *
 * Used to identify which ad platform campaign data originates from,
 * enabling multi-source ad spend tracking in analytics.
 */
enum AdSource: string
{
    case Google = 'Google';
    case Bing = 'Bing';
    case Facebook = 'Facebook';

    /**
     * Get single-character prefix for deduplication IDs.
     *
     * Used in Mixpanel $insert_id generation: "{prefix}-{date}-{campaignId}"
     * e.g., "G-2025-12-08-123456" for Google campaigns.
     */
    public function prefix(): string
    {
        return $this->value[0];
    }

    /**
     * Get lowercase source identifier for UTM parameters.
     *
     * Returns lowercase version for utm_source property matching
     * standard UTM conventions (e.g., 'google', 'bing', 'facebook').
     */
    public function utmSource(): string
    {
        return \mb_strtolower($this->value);
    }
}
