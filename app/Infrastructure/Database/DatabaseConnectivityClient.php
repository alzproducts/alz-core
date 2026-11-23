<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Application\Contracts\DatabaseConnectivityInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use InvalidArgumentException;

final readonly class DatabaseConnectivityClient implements DatabaseConnectivityInterface
{
    public function __construct(
        private DatabaseGateway $gateway,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException
     * @throws DuplicateRecordException
     * @throws DatabaseOperationFailedException
     */
    public function verifyConnectivity(?string $connection = null): void
    {
        try {
            $this->gateway->query(fn(): array => $this->gateway->connection($connection)->select('select 1'));
        } catch (InvalidArgumentException $e) {
            throw new DatabaseOperationFailedException(operation: 'connect', reason: $e->getMessage(), previous: $e);
        }
    }
}
