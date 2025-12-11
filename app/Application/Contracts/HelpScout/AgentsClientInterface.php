<?php

declare(strict_types=1);

namespace App\Application\Contracts\HelpScout;

use App\Domain\CustomerService\ValueObjects\SupportAgent;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;

/**
 * HelpScout Users/Agents API client contract.
 */
interface AgentsClientInterface
{
    /**
     * Find a support agent by email address.
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function findByEmail(string $email): ?SupportAgent;

    /**
     * Get all support agents.
     *
     * @return array<int, SupportAgent>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function list(): array;
}
