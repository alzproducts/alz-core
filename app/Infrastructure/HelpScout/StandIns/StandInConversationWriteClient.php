<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\StandIns;

use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Application\HelpScout\Commands\CreateCustomerConversationCommand;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Integrations\StandInCallLogger;
use Random\RandomException;

final readonly class StandInConversationWriteClient implements ConversationWriteClientInterface
{
    public function __construct(private StandInCallLogger $logger) {}

    public function createConversationFromCustomer(CreateCustomerConversationCommand $command): int
    {
        // intentional: positive and distinct per call — callers wrap it in IntId and store it per submission.
        try {
            $conversationId = \random_int(100_000_000, 999_999_999);
        } catch (RandomException) {
            $conversationId = 100_000_000;
        }

        $this->logger->record(StandInCallLogger::HELPSCOUT, 'createConversationFromCustomer', [
            'mailbox' => $command->mailbox->name,
            'conversation_id' => $conversationId,
        ]);

        return $conversationId;
    }

    public function addNoteToConversation(IntId $conversationId, string $noteText, IntId $userId): void
    {
        $this->logger->record(StandInCallLogger::HELPSCOUT, 'addNoteToConversation', [
            'conversation_id' => $conversationId->value,
            'user_id' => $userId->value,
        ]);
    }
}
