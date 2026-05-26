<?php

declare(strict_types=1);

namespace App\Application\Conversion\CallTracking\UseCases;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingCallRepositoryInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Application\HelpScout\Commands\CreateCustomerConversationCommand;
use App\Domain\Conversion\CallTracking\Enums\CallStatus;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingCall;
use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\CustomerService\Enums\ConversationStatus;
use App\Domain\CustomerService\Enums\ConversationType;
use App\Domain\CustomerService\Enums\Mailbox;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\Uuid;
use Psr\Log\LoggerInterface;

/**
 * Process an inbound call notification: log the call and open a HelpScout
 * phone conversation for support visibility.
 *
 * Idempotency: the dispatcher pre-generates the call UUID so a retry maps
 * back to the same row. Three branches by row state:
 *   1. Row exists with helpscoutConversationId → already complete, return.
 *   2. Row exists without conversationId       → partial retry, skip save.
 *   3. Row missing                              → new call, save first.
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
     * @throws InvalidFormatException
     * @throws MalformedStoredDataException If stored phone numbers fail E.164 validation
     * @throws UnexpectedApiResultException
     */
    public function execute(
        Uuid $callId,
        string $callerPhoneNumber,
        string $trackingNumberDialled,
        string $callSid,
    ): void {
        $this->logger->info('Processing inbound call', [
            'call_id' => $callId->value,
            'call_sid' => $callSid,
            'tracking_number' => $trackingNumberDialled,
        ]);

        $existing = $this->repository->findById($callId);
        if ($this->isAlreadyProcessed($callId, $existing)) {
            return;
        }

        $caller = PhoneNumberE164::from($callerPhoneNumber);
        $this->ensureCallPersisted($callId, $caller, PhoneNumberE164::from($trackingNumberDialled), $existing);
        $this->openConversationAndLink($callId, $caller);
    }

    private function isAlreadyProcessed(Uuid $callId, ?CallTrackingCall $existing): bool
    {
        if ($existing === null || $existing->helpscoutConversationId === null) {
            return false;
        }

        $this->logger->info('Inbound call already fully processed; skipping', [
            'call_id' => $callId->value,
            'helpscout_conversation_id' => $existing->helpscoutConversationId->value,
        ]);

        return true;
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function ensureCallPersisted(
        Uuid $callId,
        PhoneNumberE164 $caller,
        PhoneNumberE164 $tracking,
        ?CallTrackingCall $existing,
    ): void {
        if ($existing !== null) {
            return;
        }

        $this->repository->save(new CallTrackingCall(
            trackingNumberDialled: $tracking,
            callerPhoneNumber: $caller,
            callStatus: CallStatus::Initiated,
            id: $callId,
        ));
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
    private function openConversationAndLink(Uuid $callId, PhoneNumberE164 $caller): void
    {
        $conversationId = $this->conversationClient->createConversationFromCustomer(
            self::buildPhoneConversationCommand($caller),
        );

        $this->repository->setHelpScoutConversationId($callId, IntId::from($conversationId));

        $this->logger->info('Inbound call processed', [
            'call_id' => $callId->value,
            'helpscout_conversation_id' => $conversationId,
        ]);
    }

    private static function buildPhoneConversationCommand(PhoneNumberE164 $caller): CreateCustomerConversationCommand
    {
        return new CreateCustomerConversationCommand(
            email: self::buildPlaceholderEmail($caller),
            name: $caller->value,
            subject: 'Inbound call from ' . $caller->value,
            body: 'This is a Tracked Conversion Call',
            mailbox: Mailbox::Support,
            type: ConversationType::Phone,
            status: ConversationStatus::Active,
            phone: $caller->value,
        );
    }

    private static function buildPlaceholderEmail(PhoneNumberE164 $caller): string
    {
        $digits = \mb_ltrim($caller->value, '+');

        return "call-{$digits}@phone.placeholder.local";
    }
}
