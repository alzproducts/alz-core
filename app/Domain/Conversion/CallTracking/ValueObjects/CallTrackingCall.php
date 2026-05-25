<?php

declare(strict_types=1);

namespace App\Domain\Conversion\CallTracking\ValueObjects;

use App\Domain\Conversion\CallTracking\Enums\CallStatus;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\Uuid;
use DateTimeImmutable;

final readonly class CallTrackingCall
{
    public function __construct(
        public PhoneNumberE164 $trackingNumberDialled,
        public PhoneNumberE164 $callerPhoneNumber,
        public CallStatus $callStatus,
        public ?IntId $helpscoutConversationId = null,
        public ?Uuid $id = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}
}
