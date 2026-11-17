<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\StandIns;

use App\Application\Contracts\HelpScout\ContactConversationLookupInterface;
use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Infrastructure\Integrations\StandInCallLogger;

final readonly class StandInContactConversationLookup implements ContactConversationLookupInterface
{
    public function __construct(private StandInCallLogger $logger) {}

    public function findMatches(ConversationQueryParams $params): array
    {
        $this->logger->record(StandInCallLogger::HELPSCOUT, 'findMatches');

        return [];
    }
}
