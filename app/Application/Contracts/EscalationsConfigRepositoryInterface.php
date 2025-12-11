<?php

/** @noinspection PhpClassNamingConventionInspection */

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\CustomerService\ValueObjects\EscalationsConfig;

/**
 * Repository for loading escalation configuration.
 */
interface EscalationsConfigRepositoryInterface
{
    /**
     * Get the current escalations configuration.
     *
     * Returns null if no configuration is found or disabled.
     */
    public function get(): EscalationsConfig;
}
