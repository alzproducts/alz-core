<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface DatabaseConnectivityInterface
{
    /**
     * Open a connection to the named connection, or the default one when null.
     *
     * @throws ExternalServiceUnavailableException When the database cannot be reached
     * @throws DuplicateRecordException Never on connect; declared because the gateway may raise it
     * @throws DatabaseOperationFailedException When the connection is not configured or fails permanently
     */
    public function verifyConnectivity(?string $connection = null): void;
}
