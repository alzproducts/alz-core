<?php

declare(strict_types=1);

namespace App\Application\Conversion\CallTracking\UseCases;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingCallRepositoryInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Application\HelpScout\Commands\CreateCustomerConversationCommand;
use App\Domain\Conversion\CallTracking\Enums\CallStatus;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingCall;
use App\Domain\CustomerService\Enums\ConversationStatus;
use App\Domain\CustomerService\Enums\ConversationType;
use App\Domain\CustomerService\Enums\Mailbox;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Process an inbound call notification: log the call and open a HelpScout
 * phone conversation for support visibility.
 *
 * Idempotency is handled by infrastructure guarantees:
 *   - `call_sid` unique constraint + INSERT ON CONFLICT DO NOTHING → safe to re-save
 *   - Job-level Skip middleware → already-complete calls never reach here
 */
final readonly class ProcessInboundCallUseCase
{
    public function __construct(
        private CallTrackingCallRepositoryInterface $repository,
        private ConversationWriteClientInterface $conversationClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws AuthenticationExpiredException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InsufficientDataException
     * @throws InvalidApiRequestException
     * @throws UnexpectedApiResultException
     */
    public function execute(
        string $callSid,
        string $callerPhoneNumber,
        string $trackingNumberDialled,
    ): void {
        $this->logger->info('Processing inbound call', [
            'call_sid' => $callSid,
            'tracking_number' => $trackingNumberDialled,
        ]);

        $this->repository->saveOrIgnore(new CallTrackingCall(
            callSid: $callSid,
            trackingNumberDialled: $trackingNumberDialled,
            callerPhoneNumber: $callerPhoneNumber,
            callStatus: CallStatus::Initiated,
        ));

        $this->openConversationAndLink($callSid, $callerPhoneNumber);
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InsufficientDataException
     * @throws InvalidApiRequestException
     * @throws UnexpectedApiResultException
     */
    private function openConversationAndLink(string $callSid, string $callerPhoneNumber): void
    {
        $conversationId = $this->conversationClient->createConversationFromCustomer(
            self::buildPhoneConversationCommand($callerPhoneNumber),
        );

        $this->repository->setHelpScoutConversationIdByCallSid($callSid, IntId::from($conversationId));

        $this->logger->info('Inbound call processed', [
            'call_sid' => $callSid,
            'helpscout_conversation_id' => $conversationId,
        ]);
    }

    private static function buildPhoneConversationCommand(string $callerPhone): CreateCustomerConversationCommand
    {
        return new CreateCustomerConversationCommand(
            email: null,
            name: $callerPhone,
            subject: 'Inbound call from ' . $callerPhone,
            body: 'This is a Tracked Conversion Call',
            mailbox: Mailbox::Support,
            type: ConversationType::Phone,
            status: ConversationStatus::Active,
            phone: $callerPhone,
        );
    }
}
