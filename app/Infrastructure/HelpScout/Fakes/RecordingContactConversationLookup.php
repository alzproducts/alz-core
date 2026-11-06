<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Fakes;

use App\Application\Contracts\HelpScout\ContactConversationLookupInterface;
use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\OutboundCallRecorder;

final readonly class RecordingContactConversationLookup implements ContactConversationLookupInterface
{
    public function __construct(private OutboundCallRecorder $recorder) {}

    public function findMatches(ConversationQueryParams $params): array
    {
        $this->recorder->record(LiveIntegration::HelpScout, 'findMatches');

        return [];
    }
}
