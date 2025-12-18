<?php

declare(strict_types=1);

namespace App\Application\HelpScout\Support;

use App\Application\Contracts\HelpScout\MailboxesClientInterface;
use App\Application\Support\CacheTimesTrait;
use App\Application\Support\GracefulCache;
use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;

/**
 * Enriches conversations with mailbox names.
 *
 * Resolves mailbox IDs to human-readable names using cached mailbox data.
 * Used by conversation queries to add mailbox context for display.
 */
final readonly class MailboxEnrichmentService
{
    use CacheTimesTrait;

    private const string CACHE_KEY = 'helpscout:mailboxes';

    public function __construct(
        private MailboxesClientInterface $mailboxesClient,
        private GracefulCache $cache,
    ) {}

    /**
     * Enrich conversations with mailbox names.
     *
     * @param list<Conversation> $conversations
     *
     * @return list<Conversation>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function enrich(array $conversations): array
    {
        if ($conversations === []) {
            return [];
        }

        $nameMap = $this->getMailboxNameMap();

        return \array_map(
            static fn(Conversation $c): Conversation => $c->withMailboxName($nameMap[$c->mailboxId] ?? null),
            $conversations,
        );
    }

    /**
     * Get mailbox ID → name mapping (cached for 7 days).
     *
     * @return array<int, string>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    private function getMailboxNameMap(): array
    {
        $mailboxes = $this->cache->remember(
            self::CACHE_KEY,
            self::SEVEN_DAYS,
            fn(): array => $this->mailboxesClient->list(),
        );

        $nameMap = [];
        foreach ($mailboxes as $mailbox) {
            $nameMap[$mailbox->id] = $mailbox->name;
        }

        return $nameMap;
    }
}
