<?php

declare(strict_types=1);

namespace App\Infrastructure\CallTracking\Mappers;

use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingCall;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\Uuid;
use App\Infrastructure\CallTracking\Models\CallTrackingCallModel;

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

    public static function fromModel(CallTrackingCallModel $model): CallTrackingCall
    {
        return new CallTrackingCall(
            callSid: $model->call_sid,
            trackingNumberDialled: $model->tracking_number_dialled,
            callerPhoneNumber: $model->caller_phone_number,
            callStatus: $model->call_status,
            helpscoutConversationId: $model->helpscout_conversation_id !== null
                ? IntId::from($model->helpscout_conversation_id)
                : null,
            id: Uuid::fromTrusted($model->id),
            createdAt: $model->created_at->toDateTimeImmutable(),
        );
    }
}
