<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Conversion\CallTracking\UseCases;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingCallRepositoryInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Application\Conversion\CallTracking\UseCases\ProcessInboundCallUseCase;
use App\Application\HelpScout\Commands\CreateCustomerConversationCommand;
use App\Domain\Conversion\CallTracking\Enums\CallStatus;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingCall;
use App\Domain\CustomerService\Enums\ConversationStatus;
use App\Domain\CustomerService\Enums\ConversationType;
use App\Domain\CustomerService\Enums\Mailbox;
use App\Domain\ValueObjects\IntId;
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
    public function it_saves_call_creates_conversation_and_links_them(): void
    {
        $this->expectOpeningLog();

        $this->repository
            ->shouldReceive('saveOrIgnore')
            ->once()
            ->with(Mockery::on(static fn(CallTrackingCall $call): bool => $call->callSid === self::CALL_SID
                    && $call->callerPhoneNumber === self::CALLER
                    && $call->trackingNumberDialled === self::TRACKING
                    && $call->callStatus === CallStatus::Initiated));

        $this->expectConversationCreate();

        $this->repository
            ->shouldReceive('setHelpScoutConversationIdByCallSid')
            ->once()
            ->with(
                self::CALL_SID,
                Mockery::on(static fn(IntId $conv): bool => $conv->value === self::CONVERSATION_ID),
            );

        $this->expectClosingLog();

        $this->useCase->execute(self::CALL_SID, self::CALLER, self::TRACKING);
    }

    #[Test]
    public function it_creates_phone_conversation_with_null_email(): void
    {
        $this->expectOpeningLog();
        $this->repository->shouldReceive('saveOrIgnore')->once();

        $this->conversationClient
            ->shouldReceive('createConversationFromCustomer')
            ->once()
            ->with(Mockery::on(static fn(CreateCustomerConversationCommand $command): bool => $command->email === null
                    && $command->phone === self::CALLER))
            ->andReturn(self::CONVERSATION_ID);

        $this->repository->shouldReceive('setHelpScoutConversationIdByCallSid')->once();
        $this->expectClosingLog();

        $this->useCase->execute(self::CALL_SID, self::CALLER, self::TRACKING);
    }

    private function expectOpeningLog(): void
    {
        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Processing inbound call', [
                'call_sid' => self::CALL_SID,
                'tracking_number' => self::TRACKING,
            ]);
    }

    private function expectClosingLog(): void
    {
        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Inbound call processed', [
                'call_sid' => self::CALL_SID,
                'helpscout_conversation_id' => self::CONVERSATION_ID,
            ]);
    }

    private function expectConversationCreate(): void
    {
        $this->conversationClient
            ->shouldReceive('createConversationFromCustomer')
            ->once()
            ->with(Mockery::on(static fn(CreateCustomerConversationCommand $command): bool => $command->email === null
                    && $command->name === self::CALLER
                    && $command->subject === 'Inbound call from ' . self::CALLER
                    && $command->body === 'This is a Tracked Conversion Call'
                    && $command->mailbox === Mailbox::Support
                    && $command->type === ConversationType::Phone
                    && $command->status === ConversationStatus::Active
                    && $command->phone === self::CALLER))
            ->andReturn(self::CONVERSATION_ID);
    }
}
