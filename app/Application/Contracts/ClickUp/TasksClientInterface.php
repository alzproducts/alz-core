<?php

declare(strict_types=1);

namespace App\Application\Contracts\ClickUp;

use App\Application\ClickUp\DTOs\ClickUpTaskDataDTO;
use App\Application\ClickUp\Queries\ClickUpTaskQueryParams;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;

interface TasksClientInterface
{
    /**
     * Retrieve tasks from a ClickUp list filtered by the given query params.
     *
     * @return list<ClickUpTaskDataDTO>
     *
     * @throws AuthenticationExpiredException When the API key is invalid or revoked (401/403)
     * @throws InvalidApiRequestException When the request is malformed (400/422)
     * @throws InvalidApiResponseException When the response cannot be parsed
     * @throws ResourceNotFoundException When the list does not exist (404)
     * @throws ExternalServiceUnavailableException When ClickUp is rate-limited or unavailable
     */
    public function getTasksForList(ApiKeyToken $token, string $listId, ClickUpTaskQueryParams $params): array;

    /**
     * Update a task's status to the supplied value.
     *
     * @throws ResourceNotFoundException When the task does not exist (404)
     * @throws AuthenticationExpiredException When the API key is invalid or revoked (401/403)
     * @throws InvalidApiRequestException When the request is malformed (400/422)
     * @throws ExternalServiceUnavailableException When ClickUp is rate-limited or unavailable
     */
    public function updateStatus(ApiKeyToken $token, string $taskId, string $status): void;
}
