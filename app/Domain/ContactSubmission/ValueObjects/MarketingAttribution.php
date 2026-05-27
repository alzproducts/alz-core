<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\ValueObjects;

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
     * The click ID we use to deduplicate a returning visitor against an earlier
     * visit. Gclid takes precedence over msclkid when both are present so a
     * Google-then-Bing repeat visitor reuses the Google-attributed visit.
     */
    public function primaryClickId(): ?string
    {
        return $this->gclid ?? $this->msclkid;
    }

    public static function empty(): self
    {
        return new self();
    }
}
