<?php

declare(strict_types=1);

namespace App\Application\Conversion\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Fails a lead conversion action on behalf of the worker holding its claim (ADR-0012).
 */
final readonly class HandleLeadConversionFailureUseCase
{
    public function __construct(
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return bool false when the claim was lost and the row is unchanged
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException When DB unavailable
     */
    public function execute(string $submissionId, string $actionId, string $claimToken, string $message): bool
    {
        $context = ['submission_id' => $submissionId, 'action_id' => $actionId];

        $this->logger->info('Failing lead conversion action', $context);

        if (! $this->actionRepository->failClaim($actionId, $claimToken, $message)) {
            $this->logger->warning('Lead conversion action claim lost — failure not recorded', $context);

            return false;
        }

        $this->logger->error('Lead conversion permanently failed', [...$context, 'exception' => $message]);

        return true;
    }
}
