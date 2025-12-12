<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Clients;

use App\Application\Contracts\HelpScout\MailboxesClientInterface;
use App\Domain\CustomerService\ValueObjects\Mailbox as DomainMailbox;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use App\Infrastructure\HelpScout\HelpScoutResponseParser;
use App\Infrastructure\HelpScout\Responses\MailboxResponse;

/**
 * HelpScout Mailboxes API Client.
 *
 * Handles mailbox queries for the account. Transforms Infrastructure DTOs
 * to Domain value objects at the boundary.
 *
 * @see https://developer.helpscout.com/mailbox-api/endpoints/mailboxes/
 */
final readonly class MailboxesClient implements MailboxesClientInterface
{
    private const string ENDPOINT = '/mailboxes';

    public function __construct(
        private HelpScoutHttpTransport $transport,
    ) {}

    /**
     * Get all mailboxes for the account.
     *
     * @return list<DomainMailbox>
     *
     * @see https://developer.helpscout.com/mailbox-api/endpoints/mailboxes/list/
     */
    public function list(): array
    {
        /** @var list<DomainMailbox> */
        return HelpScoutResponseParser::parseEmbeddedCollectionToDomain(
            $this->transport->get(self::ENDPOINT),
            'mailboxes',
            MailboxResponse::class,
        );
    }
}
