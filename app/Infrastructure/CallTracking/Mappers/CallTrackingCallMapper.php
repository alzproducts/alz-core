<?php

declare(strict_types=1);

namespace App\Infrastructure\CallTracking\Mappers;

use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingCall;

final class CallTrackingCallMapper
{
    /**
     * @return array<string, mixed>
     */
    public static function toModelAttributes(CallTrackingCall $call): array
    {
        return [
            'call_sid' => $call->callSid,
            'tracking_number_dialled' => $call->trackingNumberDialled,
            'caller_phone_number' => $call->callerPhoneNumber,
            'call_status' => $call->callStatus,
        ];
    }
}
