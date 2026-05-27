<?php

declare(strict_types=1);

namespace App\Application\Conversion\CallTracking\Commands;

use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ValueObjects\IpAddress;

final readonly class AssignTrackingNumberCommand
{
    public function __construct(
        public MarketingAttribution $attribution,
        public bool $marketingConsentGranted,
        public IpAddress $ipAddress,
        public ?string $userAgent,
    ) {}
}
