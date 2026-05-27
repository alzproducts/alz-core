<?php

declare(strict_types=1);

namespace App\Infrastructure\CallTracking\Models;

use App\Domain\Conversion\CallTracking\Enums\CallStatus;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingCall;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\Uuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * `helpscout_conversation_id` stays null until the conversation has been
 * opened, which is what makes mid-pipeline retries observable.
 *
 * @property string $id
 * @property string $call_sid
 * @property string $tracking_number_dialled
 * @property string $caller_phone_number
 * @property int|null $helpscout_conversation_id
 * @property CallStatus $call_status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class CallTrackingCallModel extends Model
{
    use HasUuids;

    protected $table = 'customer_service.call_tracking_calls';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'helpscout_conversation_id' => 'integer',
            'call_status' => CallStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function toDomain(): CallTrackingCall
    {
        return new CallTrackingCall(
            callSid: $this->call_sid,
            trackingNumberDialled: $this->tracking_number_dialled,
            callerPhoneNumber: $this->caller_phone_number,
            callStatus: $this->call_status,
            helpscoutConversationId: $this->helpscout_conversation_id !== null
                ? IntId::from($this->helpscout_conversation_id)
                : null,
            id: Uuid::fromTrusted($this->id),
            createdAt: $this->created_at->toDateTimeImmutable(),
        );
    }
}
