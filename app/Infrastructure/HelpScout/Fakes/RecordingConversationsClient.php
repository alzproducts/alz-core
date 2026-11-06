<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Fakes;

use App\Application\Contracts\HelpScout\ConversationsClientInterface;
use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\OutboundCallRecorder;

final readonly class RecordingConversationsClient implements ConversationsClientInterface
{
    public function __construct(private OutboundCallRecorder $recorder) {}

    public function getConversations(ConversationQueryParams $params): array
    {
        $this->recorder->record(LiveIntegration::HelpScout, 'getConversations');

        return [];
    }

    public function getConversationsBatch(array $queries): array
    {
        $this->recorder->record(LiveIntegration::HelpScout, 'getConversationsBatch');

        return \array_map(static fn(): array => [], $queries);
    }
}
