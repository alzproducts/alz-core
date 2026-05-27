<?php

declare(strict_types=1);

namespace App\Application\Conversion\CallTracking\Results;

use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\ValueObjects\Uuid;

final readonly class AssignTrackingNumberResult
{
    public function __construct(
        public PhoneNumberE164 $phoneNumber,
        public ?Uuid $callVisitId,
    ) {}
}
