<?php

/** @noinspection PhpClassNamingConventionInspection */

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\CustomerService\ValueObjects\EscalationsConfig;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\ConfigurationNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Repository for loading escalation configuration.
 */
interface EscalationsConfigRepositoryInterface
{
    /**
     * Get the current escalations configuration.
     *
     * @throws ConfigurationNotFoundException When config is missing or disabled
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database is unavailable
     */
    public function get(): EscalationsConfig;
}
