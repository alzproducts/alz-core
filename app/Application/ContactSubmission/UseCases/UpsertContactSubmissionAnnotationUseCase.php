<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionDashboardQueryRepositoryInterface;
use App\Application\Contracts\Conversion\PotentialConversion\PotentialConversionAnnotationRepositoryInterface;
use App\Application\Conversion\PotentialConversion\Commands\UpsertAnnotationCommand;
use App\Domain\ContactSubmission\Enums\ContactSubmissionAnnotationField;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Write use case for partial-patch updates to a potential-conversion row's annotation.
 *
 * Verifies the row exists in the unified dashboard view (works for both form submissions and
 * call rows) and dispatches to the annotation repository's merge-patch upsert. The command
 * already carries the full change set (touched columns to set + columns to clear); columns not
 * referenced are left untouched on existing rows.
 */
final readonly class UpsertContactSubmissionAnnotationUseCase
{
    public function __construct(
        private ContactSubmissionDashboardQueryRepositoryInterface $dashboardQueryRepository,
        private PotentialConversionAnnotationRepositoryInterface $annotationRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws RecordNotFoundException When the conversion row does not exist
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(UpsertAnnotationCommand $command): void
    {
        $this->logger->info('Upserting contact submission annotation', [
            'source_id' => $command->sourceId,
            'fields_set' => \array_keys($command->valuesToSet),
            'fields_cleared' => \array_map(
                static fn(ContactSubmissionAnnotationField $c): string => $c->value,
                $command->columnsToClear,
            ),
        ]);

        $this->dashboardQueryRepository->findStageById($command->sourceId);

        $this->annotationRepository->upsert($command);

        $this->logger->info('Upserted contact submission annotation', [
            'source_id' => $command->sourceId,
        ]);
    }
}
