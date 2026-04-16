<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\ValueObjects;

/**
 * Marketing attribution parameters from the form submission.
 *
 * Click IDs and UTM params are flattened columns in the database for
 * efficient querying when correlating with order conversions.
 *
 * Indexed click IDs (primary conversion tracking): gclid, msclkid, fbclid
 * Unindexed: gclsrc (flag value), wbraid/gbraid (Google privacy alternatives, rarely populated)
 */
final readonly class MarketingAttribution
{
    public function __construct(
        public ?string $gclid = null,
        public ?string $gclsrc = null,
        public ?string $wbraid = null,
        public ?string $gbraid = null,
        public ?string $msclkid = null,
        public ?string $fbclid = null,
        public ?string $utmSource = null,
        public ?string $utmMedium = null,
        public ?string $utmCampaign = null,
        public ?string $utmContent = null,
        public ?string $utmTerm = null,
    ) {}

    /**
     * Whether any attribution data is present.
     */
    public function hasAnyAttribution(): bool
    {
        return \array_any(
            [$this->gclid, $this->gclsrc, $this->wbraid, $this->gbraid,
                $this->msclkid, $this->fbclid, $this->utmSource, $this->utmMedium,
                $this->utmCampaign, $this->utmContent, $this->utmTerm],
            static fn(?string $value): bool => $value !== null,
        );
    }

    /**
     * Create an empty attribution.
     */
    public static function empty(): self
    {
        return new self();
    }
}
