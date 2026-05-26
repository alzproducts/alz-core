<?php

declare(strict_types=1);

namespace App\Domain\Conversion\CallTracking\ValueObjects;

use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ValueObjects\IpAddress;
use App\Domain\ValueObjects\Uuid;
use DateTimeImmutable;

final readonly class CallTrackingVisit
{
    public function __construct(
        public MarketingAttribution $attribution,
        public bool $marketingConsentGranted,
        public PhoneNumberE164 $trackingNumberShown,
        public IpAddress $ipAddress,
        public ?string $userAgent = null,
        public ?Uuid $id = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}
}
