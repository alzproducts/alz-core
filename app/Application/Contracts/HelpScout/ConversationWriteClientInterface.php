<?php

declare(strict_types=1);

namespace App\Application\Contracts\HelpScout;

use App\Application\HelpScout\Commands\CreateCustomerConversationCommand;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\ValueObjects\IntId;

/**
 * HelpScout Conversations write operations client contract.
 *
 * Separate from read operations because:
 * - Writes use SDK (clean serialization)
 * - Reads use direct HTTP (SDK hydration drops fields)
 */
interface ConversationWriteClientInterface
{
    /**
     * Create a customer-initiated conversation in HelpScout.
     *
     * Creates a conversation with a CustomerThread - used when the customer
     * contacts support (e.g., contact form). HelpScout auto-creates the
     * customer by email if they don't exist.
     *
     * @return int The created conversation ID
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws UnexpectedApiResultException When API returns null conversation ID
     * @throws InsufficientDataException When customer has no email or phone
     */
    public function createConversationFromCustomer(CreateCustomerConversationCommand $command): int;

    /**
     * Add an internal note to an existing conversation.
     *
     * Notes are staff-only (not visible to customers). Used for automated
     * annotations like email validation warnings.
     *
     * @param IntId $conversationId The conversation to add the note to
     * @param string $noteText The note content (plain text)
     * @param IntId $userId The HelpScout user ID to attribute the note to
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiRequestException When request parameters invalid
     */
    public function addNoteToConversation(IntId $conversationId, string $noteText, IntId $userId): void;
}
