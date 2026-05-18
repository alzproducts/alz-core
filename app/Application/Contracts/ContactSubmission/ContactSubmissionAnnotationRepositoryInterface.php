<?php

declare(strict_types=1);

namespace App\Application\Contracts\ContactSubmission;

use App\Application\ContactSubmission\Commands\UpsertAnnotationCommand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface ContactSubmissionAnnotationRepositoryInterface
{
    /**
     * Upsert the annotation row for a contact submission using partial-update semantics.
     *
     * Only columns referenced by `$command->valuesToSet` or `$command->columnsToClear` participate
     * in the ON CONFLICT DO UPDATE; untouched columns retain their previous values.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function upsert(UpsertAnnotationCommand $command): void;
}
