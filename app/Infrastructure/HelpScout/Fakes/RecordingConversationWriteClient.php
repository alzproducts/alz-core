<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Fakes;

use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Application\HelpScout\Commands\CreateCustomerConversationCommand;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\OutboundCallRecorder;
use Random\RandomException;

final readonly class RecordingConversationWriteClient implements ConversationWriteClientInterface
{
    public function __construct(private OutboundCallRecorder $recorder) {}

    public function createConversationFromCustomer(CreateCustomerConversationCommand $command): int
    {
        try {
            $conversationId = \random_int(100_000_000, 999_999_999);
        } catch (RandomException) {
            $conversationId = 100_000_000;
        }

        $this->recorder->record(LiveIntegration::HelpScout, 'createConversationFromCustomer', [
            'mailbox' => $command->mailbox->name,
            'conversation_id' => $conversationId,
        ]);

        return $conversationId;
    }

    public function addNoteToConversation(IntId $conversationId, string $noteText, IntId $userId): void
    {
        $this->recorder->record(LiveIntegration::HelpScout, 'addNoteToConversation', [
            'conversation_id' => $conversationId->value,
            'user_id' => $userId->value,
        ]);
    }
}
