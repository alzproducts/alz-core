<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\Services;

use App\Application\Contracts\EmailValidationServiceInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Application\HelpScout\Config\HelpScoutSystemUserId;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Warns support on a HelpScout conversation when the submitter's email fails validation.
 *
 * Best-effort: the note is informational, so a failure is logged and never thrown.
 */
final readonly class EmailValidationNoteService
{
    public function __construct(
        private ConversationWriteClientInterface $helpScoutClient,
        private EmailValidationServiceInterface $emailValidator,
        private HelpScoutSystemUserId $helpScoutSystemUserId,
        private LoggerInterface $logger,
    ) {}

    public function addIfInvalid(string $email, int $conversationId): void
    {
        if ($this->emailValidator->isValid($email)) {
            return;
        }

        $noteText = "⚠️ Email validation warning: The email address '{$email}' may be invalid "
            . '(failed RFC/DNS check). Please verify before replying.';

        $this->addNonBlockingNote($conversationId, $noteText);
    }

    private function addNonBlockingNote(int $conversationId, string $noteText): void
    {
        try {
            $this->helpScoutClient->addNoteToConversation(
                IntId::from($conversationId),
                $noteText,
                $this->helpScoutSystemUserId->value,
            );

            $this->logger->info('Added note to HelpScout conversation', [
                'conversation_id' => $conversationId,
            ]);
        } catch (Throwable $e) { // @ignoreException - Non-critical: note failure shouldn't fail submission
            $this->logger->error('Failed to add note to HelpScout conversation', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
