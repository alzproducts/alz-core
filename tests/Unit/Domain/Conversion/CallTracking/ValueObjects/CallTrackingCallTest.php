<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Conversion\CallTracking\ValueObjects;

use App\Domain\Conversion\CallTracking\Enums\CallStatus;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingCall;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CallTrackingCall::class)]
final class CallTrackingCallTest extends TestCase
{
    private const string CALL_SID = 'CA1234567890abcdef1234567890abcdef';

    private const string TRACKING = '+441234567890';

    private const string CALLER = '+447712345678';

    #[Test]
    public function it_constructs_with_the_required_fields(): void
    {
        $call = new CallTrackingCall(
            callSid: self::CALL_SID,
            trackingNumberDialled: self::TRACKING,
            callerPhoneNumber: self::CALLER,
            callStatus: CallStatus::Initiated,
        );

        $this->assertSame(self::CALL_SID, $call->callSid);
        $this->assertSame(self::TRACKING, $call->trackingNumberDialled);
        $this->assertSame(self::CALLER, $call->callerPhoneNumber);
        $this->assertSame(CallStatus::Initiated, $call->callStatus);
        $this->assertNull($call->helpscoutConversationId);
        $this->assertNull($call->id);
        $this->assertNull($call->createdAt);
    }

    #[Test]
    public function it_accepts_a_helpscout_conversation_id(): void
    {
        $conversationId = IntId::from(123456);

        $call = new CallTrackingCall(
            callSid: self::CALL_SID,
            trackingNumberDialled: self::TRACKING,
            callerPhoneNumber: self::CALLER,
            callStatus: CallStatus::Initiated,
            helpscoutConversationId: $conversationId,
        );

        $this->assertSame($conversationId, $call->helpscoutConversationId);
        $this->assertSame(123456, $call->helpscoutConversationId->value);
    }

    #[Test]
    public function it_carries_id_and_created_at_when_hydrated(): void
    {
        $id = Uuid::fromTrusted('11111111-2222-3333-4444-555555555555');
        $createdAt = new DateTimeImmutable('2026-05-26T10:00:00+00:00');

        $call = new CallTrackingCall(
            callSid: self::CALL_SID,
            trackingNumberDialled: self::TRACKING,
            callerPhoneNumber: self::CALLER,
            callStatus: CallStatus::Initiated,
            id: $id,
            createdAt: $createdAt,
        );

        $this->assertSame($id, $call->id);
        $this->assertSame($createdAt, $call->createdAt);
    }
}
