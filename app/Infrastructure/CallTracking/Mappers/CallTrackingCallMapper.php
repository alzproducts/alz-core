<?php

declare(strict_types=1);

namespace App\Infrastructure\CallTracking\Mappers;

use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingCall;
use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
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
        $attributes = [
            'tracking_number_dialled' => $call->trackingNumberDialled->value,
            'caller_phone_number' => $call->callerPhoneNumber->value,
            'call_status' => $call->callStatus,
            'helpscout_conversation_id' => $call->helpscoutConversationId?->value,
        ];

        if ($call->id !== null) {
            $attributes['id'] = $call->id->value;
        }

        return $attributes;
    }

    /**
     * @throws MalformedStoredDataException If stored phone numbers fail E.164 validation
     */
    public static function fromModel(CallTrackingCallModel $model): CallTrackingCall
    {
        try {
            return new CallTrackingCall(
                trackingNumberDialled: PhoneNumberE164::from($model->tracking_number_dialled),
                callerPhoneNumber: PhoneNumberE164::from($model->caller_phone_number),
                callStatus: $model->call_status,
                helpscoutConversationId: $model->helpscout_conversation_id !== null
                    ? IntId::from($model->helpscout_conversation_id)
                    : null,
                id: Uuid::fromTrusted($model->id),
                createdAt: $model->created_at->toDateTimeImmutable(),
            );
        } catch (InvalidFormatException $e) {
            throw new MalformedStoredDataException(
                'call_tracking_calls',
                'stored phone number fails E.164 validation: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }
}
