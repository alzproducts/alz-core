<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Clients;

use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use App\Infrastructure\HelpScout\HelpScoutResponseParser;
use App\Infrastructure\HelpScout\Responses\Mailbox;

/**
 * HelpScout Mailboxes API Client.
 *
 * Handles mailbox queries for the account.
 *
 * @see https://developer.helpscout.com/mailbox-api/endpoints/mailboxes/
 */
final readonly class MailboxesClient
{
    use HelpScoutResponseParser;

    private const string ENDPOINT = '/mailboxes';

    public function __construct(
        private HelpScoutHttpTransport $transport,
    ) {}

    /**
     * Get all mailboxes for the account.
     *
     * @return array<Mailbox>
     *
     * @see https://developer.helpscout.com/mailbox-api/endpoints/mailboxes/list/
     */
    public function list(): array
    {
        $response = $this->transport->get(self::ENDPOINT);

        /** @var array<Mailbox> */
        return $this->parseEmbeddedCollection($response->json(), 'mailboxes', Mailbox::class);
    }
}
