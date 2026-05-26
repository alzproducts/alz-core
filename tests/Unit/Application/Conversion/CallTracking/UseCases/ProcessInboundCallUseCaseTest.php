<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Conversion\CallTracking\UseCases;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingCallRepositoryInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Application\Conversion\CallTracking\UseCases\ProcessInboundCallUseCase;
use App\Application\HelpScout\Commands\CreateCustomerConversationCommand;
use App\Domain\Conversion\CallTracking\Enums\CallStatus;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingCall;
use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\CustomerService\Enums\ConversationStatus;
use App\Domain\CustomerService\Enums\ConversationType;
use App\Domain\CustomerService\Enums\Mailbox;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\Uuid;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(ProcessInboundCallUseCase::class)]
final class ProcessInboundCallUseCaseTest extends TestCase
{
    private const string CALL_ID = '0193a1b2-c3d4-7000-8000-000000000001';

    private const string CALLER = '+447900123456';

    private const string TRACKING = '+441234567890';

    private const string CALL_SID = 'CA1234567890abcdef1234567890abcdef';

    private const int CONVERSATION_ID = 987654;

    private CallTrackingCallRepositoryInterface&MockInterface $repository;

    private ConversationWriteClientInterface&MockInterface $conversationClient;

    private LoggerInterface&MockInterface $logger;

    private ProcessInboundCallUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(CallTrackingCallRepositoryInterface::class);
        $this->conversationClient = Mockery::mock(ConversationWriteClientInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new ProcessInboundCallUseCase(
            $this->repository,
            $this->conversationClient,
            $this->logger,
        );
    }

    #[Test]
    public function it_saves_call_creates_conversation_and_links_them_on_first_attempt(): void
    {
        $callId = Uuid::fromTrusted(self::CALL_ID);

        $this->expectOpeningLog($callId);

        $this->repository
            ->shouldReceive('findById')
            ->once()
            ->with(Mockery::on(static fn(Uuid $id): bool => $id->value === self::CALL_ID))
            ->andReturnNull();

        $this->repository
            ->shouldReceive('save')
            ->once()
            ->with(Mockery::on(static fn(CallTrackingCall $call): bool => $call->callStatus === CallStatus::Initiated
                    && $call->callerPhoneNumber->value === self::CALLER
                    && $call->trackingNumberDialled->value === self::TRACKING
                    && $call->id instanceof Uuid
                    && $call->id->value === self::CALL_ID
                    && $call->helpscoutConversationId === null))
            ->andReturn($callId);

        $this->expectConversationCreate();

        $this->repository
            ->shouldReceive('setHelpScoutConversationId')
            ->once()
            ->with(
                Mockery::on(static fn(Uuid $id): bool => $id->value === self::CALL_ID),
                Mockery::on(static fn(IntId $conv): bool => $conv->value === self::CONVERSATION_ID),
            );

        $this->expectClosingLog($callId);

        $this->useCase->execute($callId, self::CALLER, self::TRACKING, self::CALL_SID);
    }

    #[Test]
    public function it_returns_early_when_call_already_has_helpscout_conversation_id(): void
    {
        $callId = Uuid::fromTrusted(self::CALL_ID);

        $this->expectOpeningLog($callId);

        $existing = new CallTrackingCall(
            trackingNumberDialled: PhoneNumberE164::from(self::TRACKING),
            callerPhoneNumber: PhoneNumberE164::from(self::CALLER),
            callStatus: CallStatus::Initiated,
            helpscoutConversationId: IntId::from(self::CONVERSATION_ID),
            id: $callId,
        );

        $this->repository
            ->shouldReceive('findById')
            ->once()
            ->andReturn($existing);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Inbound call already fully processed; skipping', [
                'call_id' => self::CALL_ID,
                'helpscout_conversation_id' => self::CONVERSATION_ID,
            ]);

        $this->repository->shouldNotReceive('save');
        $this->repository->shouldNotReceive('setHelpScoutConversationId');
        $this->conversationClient->shouldNotReceive('createConversationFromCustomer');

        $this->useCase->execute($callId, self::CALLER, self::TRACKING, self::CALL_SID);
    }

    #[Test]
    public function it_skips_save_on_partial_retry_and_finishes_conversation_link(): void
    {
        $callId = Uuid::fromTrusted(self::CALL_ID);

        $this->expectOpeningLog($callId);

        $existing = new CallTrackingCall(
            trackingNumberDialled: PhoneNumberE164::from(self::TRACKING),
            callerPhoneNumber: PhoneNumberE164::from(self::CALLER),
            callStatus: CallStatus::Initiated,
            helpscoutConversationId: null,
            id: $callId,
        );

        $this->repository
            ->shouldReceive('findById')
            ->once()
            ->andReturn($existing);

        $this->repository->shouldNotReceive('save');

        $this->expectConversationCreate();

        $this->repository
            ->shouldReceive('setHelpScoutConversationId')
            ->once()
            ->with(
                Mockery::on(static fn(Uuid $id): bool => $id->value === self::CALL_ID),
                Mockery::on(static fn(IntId $conv): bool => $conv->value === self::CONVERSATION_ID),
            );

        $this->expectClosingLog($callId);

        $this->useCase->execute($callId, self::CALLER, self::TRACKING, self::CALL_SID);
    }

    #[Test]
    public function it_uses_placeholder_email_with_phone_digits_stripped_of_leading_plus(): void
    {
        $callId = Uuid::fromTrusted(self::CALL_ID);

        $this->expectOpeningLog($callId);

        $this->repository->shouldReceive('findById')->once()->andReturnNull();
        $this->repository->shouldReceive('save')->once()->andReturn($callId);

        $this->conversationClient
            ->shouldReceive('createConversationFromCustomer')
            ->once()
            ->with(Mockery::on(static fn(CreateCustomerConversationCommand $command): bool => $command->email === 'call-447900123456@phone.placeholder.local'))
            ->andReturn(self::CONVERSATION_ID);

        $this->repository->shouldReceive('setHelpScoutConversationId')->once();
        $this->expectClosingLog($callId);

        $this->useCase->execute($callId, self::CALLER, self::TRACKING, self::CALL_SID);
    }

    private function expectOpeningLog(Uuid $callId): void
    {
        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Processing inbound call', [
                'call_id' => $callId->value,
                'call_sid' => self::CALL_SID,
                'tracking_number' => self::TRACKING,
            ]);
    }

    private function expectClosingLog(Uuid $callId): void
    {
        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Inbound call processed', [
                'call_id' => $callId->value,
                'helpscout_conversation_id' => self::CONVERSATION_ID,
            ]);
    }

    private function expectConversationCreate(): void
    {
        $this->conversationClient
            ->shouldReceive('createConversationFromCustomer')
            ->once()
            ->with(Mockery::on(static fn(CreateCustomerConversationCommand $command): bool => $command->name === self::CALLER
                    && $command->subject === 'Inbound call from ' . self::CALLER
                    && $command->body === 'This is a Tracked Conversion Call'
                    && $command->mailbox === Mailbox::Support
                    && $command->type === ConversationType::Phone
                    && $command->status === ConversationStatus::Active
                    && $command->phone === self::CALLER))
            ->andReturn(self::CONVERSATION_ID);
    }
}
