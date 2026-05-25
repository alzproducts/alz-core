<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Conversion\CallTracking\ValueObjects;

use App\Domain\Conversion\CallTracking\Enums\CallStatus;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingCall;
use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CallTrackingCall::class)]
final class CallTrackingCallTest extends TestCase
{
    #[Test]
    public function it_constructs_with_the_required_fields(): void
    {
        $tracking = PhoneNumberE164::from('+441234567890');
        $caller = PhoneNumberE164::from('+447712345678');

        $call = new CallTrackingCall(
            trackingNumberDialled: $tracking,
            callerPhoneNumber: $caller,
            callStatus: CallStatus::Initiated,
        );

        $this->assertSame($tracking, $call->trackingNumberDialled);
        $this->assertSame($caller, $call->callerPhoneNumber);
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
            trackingNumberDialled: PhoneNumberE164::from('+441234567890'),
            callerPhoneNumber: PhoneNumberE164::from('+447712345678'),
            callStatus: CallStatus::Initiated,
            helpscoutConversationId: $conversationId,
        );

        $this->assertSame($conversationId, $call->helpscoutConversationId);
        $this->assertSame(123456, $call->helpscoutConversationId->value);
    }

    #[Test]
    public function it_carries_id_and_created_at_when_hydrated(): void
    {
        $id = Guid::fromTrusted('11111111-2222-3333-4444-555555555555');
        $createdAt = new DateTimeImmutable('2026-05-26T10:00:00+00:00');

        $call = new CallTrackingCall(
            trackingNumberDialled: PhoneNumberE164::from('+441234567890'),
            callerPhoneNumber: PhoneNumberE164::from('+447712345678'),
            callStatus: CallStatus::Initiated,
            id: $id,
            createdAt: $createdAt,
        );

        $this->assertSame($id, $call->id);
        $this->assertSame($createdAt, $call->createdAt);
    }
}
