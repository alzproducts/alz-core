<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Clients;

use App\Application\Contracts\HelpScout\MailboxesClientInterface;
use App\Domain\CustomerService\ValueObjects\Mailbox as DomainMailbox;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use App\Infrastructure\HelpScout\HelpScoutResponseParser;
use App\Infrastructure\HelpScout\Responses\Mailbox;

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
    use HelpScoutResponseParser;

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
        return $this->parseEmbeddedCollectionToDomain(
            $this->transport->get(self::ENDPOINT),
            'mailboxes',
            Mailbox::class,
            self::toDomain(...),
        );
    }

    /**
     * Transform Infrastructure DTO to Domain value object.
     */
    private static function toDomain(Mailbox $mailbox): DomainMailbox
    {
        return new DomainMailbox(
            id: $mailbox->id,
            name: $mailbox->name,
            email: $mailbox->email,
            slug: $mailbox->slug ?? '',
        );
    }
}
