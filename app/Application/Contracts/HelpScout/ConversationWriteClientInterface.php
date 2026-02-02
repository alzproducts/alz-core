<?php

declare(strict_types=1);

namespace App\Application\Contracts\HelpScout;

use App\Application\HelpScout\Commands\CreateConversationCommand;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;

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
     * Create a new conversation in HelpScout.
     *
     * Creates a conversation with a customer thread. HelpScout auto-creates
     * the customer by email if they don't exist.
     *
     * @return int The created conversation ID
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiRequestException When request parameters invalid
     */
    public function createConversation(CreateConversationCommand $command): int;
}
