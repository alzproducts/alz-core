<?php

declare(strict_types=1);

namespace App\Application\Contracts\ClickUp;

use App\Application\ClickUp\DTOs\ClickUpUserDataDTO;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;

interface UsersClientInterface
{
    /**
     * Validate the token and return the authenticated ClickUp user's identity.
     *
     * @throws AuthenticationExpiredException When the API key is invalid or revoked (401/403)
     * @throws InvalidApiRequestException When the request is malformed (400/422)
     * @throws InvalidApiResponseException When the response cannot be parsed
     * @throws ResourceNotFoundException When the user endpoint returns 404
     * @throws ExternalServiceUnavailableException When ClickUp is rate-limited or unavailable
     */
    public function getUser(ApiKeyToken $token): ClickUpUserDataDTO;
}
