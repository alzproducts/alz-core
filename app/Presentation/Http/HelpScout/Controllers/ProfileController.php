<?php

declare(strict_types=1);

namespace App\Presentation\Http\HelpScout\Controllers;

use App\Application\HelpScout\Services\CachingHelpScoutService;
use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Domain\CustomerService\Exceptions\CustomerServiceAgentNotFoundException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Presentation\Http\HelpScout\Resources\AgentProfileResource;

/**
 * HelpScout user profile endpoint.
 *
 * Returns agent details for connection status display on settings page.
 */
final readonly class ProfileController
{
    public function __construct(
        private CachingHelpScoutService $service,
    ) {}

    /**
     * Get authenticated user's HelpScout profile.
     *
     * Returns agent details (name, email, role) for connection status display.
     *
     * @throws CustomerServiceAgentNotFoundException When agent email not found in HelpScout
     * @throws ExternalServiceUnavailableException When HelpScout API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function __invoke(AuthenticatedUser $user): AgentProfileResource
    {
        return new AgentProfileResource($this->service->getAgentProfile($user->email));
    }
}
