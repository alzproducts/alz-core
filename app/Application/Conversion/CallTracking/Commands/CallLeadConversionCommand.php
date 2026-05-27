<?php

declare(strict_types=1);

namespace App\Application\Conversion\CallTracking\Commands;

use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\ValueObjects\Uuid;

/**
 * `callerPhone` is included so processors can build the upload DTO without re-resolving
 * call → visit. Trade-off: the phone appears in Horizon payloads / `failed_jobs` rows.
 */
final readonly class CallLeadConversionCommand
{
    public function __construct(
        public Uuid $visitId,
        public Uuid $actionId,
        public PhoneNumberE164 $callerPhone,
    ) {}
}
