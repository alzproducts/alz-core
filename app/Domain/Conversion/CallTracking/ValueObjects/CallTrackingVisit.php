<?php

declare(strict_types=1);

namespace App\Domain\Conversion\CallTracking\ValueObjects;

use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;
use Webmozart\Assert\Assert;

final readonly class CallTrackingVisit
{
    public function __construct(
        public MarketingAttribution $attribution,
        public bool $marketingConsentGranted,
        public PhoneNumberE164 $trackingNumberShown,
        public string $ipAddress,
        public ?string $userAgent = null,
        public ?string $refererUrl = null,
        public ?Guid $id = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {
        Assert::notEmpty($ipAddress, 'IP address is required');
    }
}
