<?php

declare(strict_types=1);

namespace App\Presentation\Http\Twilio\Webhooks\DTOs;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;

/**
 * `CallStatus` is intentionally omitted: the domain always stores
 * `CallStatus::Initiated` regardless of what Twilio sends (`ringing` /
 * `initiated`), so accepting the field would imply otherwise.
 */
final class TwilioCallStartedDTO extends Data
{
    public function __construct(
        #[MapInputName('From'), Required, StringType]
        public readonly string $from,
        #[MapInputName('To'), Required, StringType]
        public readonly string $to,
        #[MapInputName('CallSid'), Required, StringType]
        public readonly string $callSid,
    ) {}
}
