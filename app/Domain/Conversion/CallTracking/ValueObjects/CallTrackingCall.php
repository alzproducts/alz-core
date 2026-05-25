<?php

declare(strict_types=1);

namespace App\Domain\Conversion\CallTracking\ValueObjects;

use App\Domain\Conversion\CallTracking\Enums\CallStatus;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;

final readonly class CallTrackingCall
{
    public function __construct(
        public PhoneNumberE164 $trackingNumberDialled,
        public PhoneNumberE164 $callerPhoneNumber,
        public CallStatus $callStatus,
        public ?IntId $helpscoutConversationId = null,
        public ?Guid $id = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}
}
