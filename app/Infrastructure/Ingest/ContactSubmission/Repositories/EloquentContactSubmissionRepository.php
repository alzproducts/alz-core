<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingest\ContactSubmission\Repositories;

use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Ingest\ContactSubmission\Mappers\ContactSubmissionMapper;
use App\Infrastructure\Ingest\ContactSubmission\Models\ContactSubmissionModel;
use App\Infrastructure\Persistence\EloquentGateway;
use RuntimeException;

/**
 * Eloquent implementation of ContactSubmissionRepository.
 *
 * Persists immutable submission snapshots to public_ingest schema.
 * Uses EloquentGateway for model-agnostic operations and exception translation.
 */
final readonly class EloquentContactSubmissionRepository implements ContactSubmissionRepositoryInterface
{
    public function __construct(
        private EloquentGateway $gateway,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RuntimeException
     */
    public function save(ContactSubmission $submission): string
    {
        return $this->gateway->insertOne(
            ContactSubmissionModel::class,
            ContactSubmissionMapper::toModelAttributes($submission),
        );
    }

    /**
     * @throws RecordNotFoundException When submission not found
     * @throws InvalidFormatException
     * @throws MalformedStoredDataException If product JSONB is corrupted
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function findById(string $id): ContactSubmission
    {
        return $this->gateway->findOrFail(
            ContactSubmissionModel::class,
            'id',
            $id,
            entityTypeName: 'ContactSubmission',
            mapper: ContactSubmissionMapper::fromModel(...),
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function existsById(string $id): bool
    {
        return $this->gateway->exists(ContactSubmissionModel::class, 'id', $id);
    }
}
