<?php

declare(strict_types=1);

namespace App\Domain\ContactForm\ValueObjects;

/**
 * Marketing attribution parameters from the form submission.
 *
 * GCLID and UTM params are flattened columns in the database for
 * efficient querying when correlating with order conversions.
 */
final readonly class MarketingAttribution
{
    public function __construct(
        public ?string $gclid = null,
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
        return $this->gclid !== null
            || $this->utmSource !== null
            || $this->utmMedium !== null
            || $this->utmCampaign !== null
            || $this->utmContent !== null
            || $this->utmTerm !== null;
    }

    /**
     * Create an empty attribution.
     */
    public static function empty(): self
    {
        return new self();
    }
}
