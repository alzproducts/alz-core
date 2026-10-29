<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\Resolvers;

use App\Application\ContactSubmission\DTOs\ActionClaimDTO;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\HelpScout\ContactConversationLookupInterface;
use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Finds the HelpScout conversation an earlier attempt created before its ID was recorded.
 *
 * A match needs the submitted email, the create's subject and a creation time no earlier than
 * the action's less clock skew, and must not already belong to another action. Anything but
 * exactly one match returns null, so the caller creates.
 */
final readonly class ExistingConversationResolver
{
    private const int CLOCK_SKEW_SECONDS = 60;

    public function __construct(
        private ContactConversationLookupInterface $lookup,
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private LoggerInterface $logger,
        private int $mailboxId,
    ) {}

    /**
     * Only a retry can find a conversation, so a first attempt returns null without a search.
     *
     * @throws ExternalServiceUnavailableException When HelpScout or the database is unavailable, or the search times out
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function resolve(
        string $submissionId,
        string $actionId,
        ActionClaimDTO $claim,
        ContactSubmission $submission,
    ): ?string {
        $email = $submission->form->email;
        $createdSince = $claim->createdAt->setTimestamp($claim->createdAt->getTimestamp() - self::CLOCK_SKEW_SECONDS);
        $params = ($claim->attempts > 1)
            ? ConversationQueryParams::contactFormRetry($email, $createdSince, $this->mailboxId)
            : null;

        if ($params === null) {
            return null;
        }

        $conversations = $this->search($params, $submissionId, $actionId);
        $candidates = self::matchingIds($conversations, $email, $submission->helpScoutSubject(), $createdSince);

        return $this->singleUnlinked($candidates, $submissionId, $actionId);
    }

    /**
     * @param list<Conversation> $conversations
     *
     * @return list<string>
     */
    private static function matchingIds(
        array $conversations,
        string $email,
        string $subject,
        DateTimeImmutable $createdSince,
    ): array {
        $matches = \array_filter(
            $conversations,
            static fn(Conversation $c): bool => self::isMatch($c, $email, $subject, $createdSince),
        );

        return \array_values(\array_map(static fn(Conversation $c): string => (string) $c->id, $matches));
    }

    /**
     * A permanent failure leaves nothing to match, so the caller creates.
     * intentional: a 401 is not re-minted here, keeping the search inside the job timeout.
     *
     * @return list<Conversation>
     *
     * @throws ExternalServiceUnavailableException
     */
    private function search(ConversationQueryParams $params, string $submissionId, string $actionId): array
    {
        try {
            return $this->lookup->findMatches($params);
        } catch (PermanentApiFailure $e) {
            $this->logger->warning('Existing HelpScout conversation search failed', [
                'submission_id' => $submissionId,
                'action_id' => $actionId,
                'exception_class' => $e::class,
            ]);

            return [];
        }
    }

    private static function isMatch(
        Conversation $conversation,
        string $email,
        string $subject,
        DateTimeImmutable $createdSince,
    ): bool {
        $customerEmail = $conversation->customer?->email;

        return $customerEmail !== null
            && \strcasecmp($customerEmail, $email) === 0
            && $conversation->subject === $subject
            && $conversation->createdAt >= $createdSince;
    }

    /**
     * @param list<string> $candidates
     *
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    private function singleUnlinked(array $candidates, string $submissionId, string $actionId): ?string
    {
        if ($candidates === []) {
            return null;
        }

        $linked = $this->actionRepository->linkedConversationIds($candidates, $actionId);
        $unlinked = \array_values(\array_diff($candidates, $linked));

        $this->logger->info('Existing HelpScout conversation search finished', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
            'unlinked_matches' => $unlinked,
        ]);

        return (\count($unlinked) === 1) ? $unlinked[0] : null;
    }
}
