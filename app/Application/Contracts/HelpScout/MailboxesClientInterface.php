<?php

declare(strict_types=1);

namespace App\Application\Contracts\HelpScout;

use App\Domain\CustomerService\ValueObjects\Mailbox;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * HelpScout Mailboxes API client contract.
 */
interface MailboxesClientInterface
{
    /**
     * Get all mailboxes.
     *
     * @return list<Mailbox>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function list(): array;
}
