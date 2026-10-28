<?php

declare(strict_types=1);

namespace App\Application\Contracts\HelpScout;

use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * Finds conversations matching a contact-form search in a single bounded request.
 *
 * The query carries submitter PII, so a failure is reported without the request URL.
 */
interface ContactConversationLookupInterface
{
    /**
     * @return list<Conversation>
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or the request times out
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function findMatches(ConversationQueryParams $params): array;
}
