<?php

/** @noinspection PhpClassNamingConventionInspection */

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\CustomerService\ValueObjects\EscalationsConfig;
use App\Domain\Exceptions\ConfigurationNotFoundException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;

/**
 * Repository for loading escalation configuration.
 */
interface EscalationsConfigRepositoryInterface
{
    /**
     * Get the current escalations configuration.
     *
     * @throws ConfigurationNotFoundException When config is missing or disabled
     * @throws ExternalServiceUnavailableException When database is unavailable
     */
    public function get(): EscalationsConfig;
}
