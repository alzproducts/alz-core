<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketing\Repositories;

use App\Application\ContactSubmission\Commands\UpsertAnnotationCommand;
use App\Application\Contracts\ContactSubmission\ContactSubmissionAnnotationRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ContactSubmissionAnnotationField;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Marketing\Models\ContactSubmissionAnnotationModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Override;

/**
 * Write-only repository for `marketing.contact_submission_annotations`.
 *
 * Partial-update semantics: only columns referenced by the command participate in the
 * ON CONFLICT DO UPDATE list, so unsent columns retain their values across upserts.
 */
final readonly class EloquentContactSubmissionAnnotationRepository implements ContactSubmissionAnnotationRepositoryInterface
{
    public function __construct(
        private EloquentGateway $eloquentGateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function upsert(UpsertAnnotationCommand $command): void
    {
        $this->eloquentGateway->upsertOne(
            modelClass: ContactSubmissionAnnotationModel::class,
            attributes: [
                'contact_submission_id' => $command->contactSubmissionId,
                ...$command->valuesToSet,
                ...\array_fill_keys(
                    \array_map(
                        static fn(ContactSubmissionAnnotationField $c): string => $c->value,
                        $command->columnsToClear,
                    ),
                    null,
                ),
            ],
            uniqueBy: ['contact_submission_id'],
        );
    }
}
