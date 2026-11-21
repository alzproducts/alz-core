<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;

/**
 * The one stored QBO connection, scoped to the configured QBO environment.
 */
interface QuickBooksConnectionRepositoryInterface
{
    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws CorruptApiKeyException
     */
    public function find(): ?QuickBooksConnection;

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws KeyEncryptionFailedException
     */
    public function save(QuickBooksConnection $connection): void;

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function delete(): void;
}
