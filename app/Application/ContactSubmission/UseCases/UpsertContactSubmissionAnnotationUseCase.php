<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\Commands\UpsertAnnotationCommand;
use App\Application\Contracts\ContactSubmission\ContactSubmissionAnnotationRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ContactSubmissionAnnotationField;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Write use case for partial-patch updates to a contact submission's annotation row.
 *
 * Verifies the parent submission exists (via a lightweight EXISTS probe) and dispatches
 * to the annotation repository's merge-patch upsert. The command already carries the
 * full change set (touched columns to set + columns to clear); columns not referenced
 * are left untouched on existing rows.
 */
final readonly class UpsertContactSubmissionAnnotationUseCase
{
    public function __construct(
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private ContactSubmissionAnnotationRepositoryInterface $annotationRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws RecordNotFoundException When the parent contact submission does not exist
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(UpsertAnnotationCommand $command): void
    {
        $this->logger->info('Upserting contact submission annotation', [
            'contact_submission_id' => $command->contactSubmissionId,
            'fields_set' => \array_keys($command->valuesToSet),
            'fields_cleared' => \array_map(
                static fn(ContactSubmissionAnnotationField $c): string => $c->value,
                $command->columnsToClear,
            ),
        ]);

        if (! $this->submissionRepository->existsById($command->contactSubmissionId)) {
            throw new RecordNotFoundException('ContactSubmission', $command->contactSubmissionId);
        }

        $this->annotationRepository->upsert($command);

        $this->logger->info('Upserted contact submission annotation', [
            'contact_submission_id' => $command->contactSubmissionId,
        ]);
    }
}
