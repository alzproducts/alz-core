<?php

declare(strict_types=1);

namespace App\Infrastructure\ClickUp\Clients;

use App\Application\ClickUp\DTOs\ClickUpTaskDataDTO;
use App\Application\ClickUp\Queries\ClickUpTaskQueryParams;
use App\Application\Contracts\ClickUp\TasksClientInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\ClickUp\ClickUpErrorHandler;
use App\Infrastructure\ClickUp\ClickUpHttpTransport;
use App\Infrastructure\ClickUp\Responses\TaskResponse;
use Exception;

final readonly class TasksClient implements TasksClientInterface
{
    public function __construct(
        private ClickUpHttpTransport $transport,
    ) {}

    /**
     * @return list<ClickUpTaskDataDTO>
     *
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    public function getTasksForList(ApiKeyToken $token, string $listId, ClickUpTaskQueryParams $params): array
    {
        $query = self::buildQuery($params);
        $response = $this->transport->get($token, "/list/{$listId}/task", $query);

        try {
            $rawTasks = $response->json('tasks') ?? [];
            $taskArray = \is_array($rawTasks) ? $rawTasks : [];

            $tasks = [];
            foreach ($taskArray as $task) {
                if (\is_array($task)) {
                    $tasks[] = TaskResponse::from($task)->toDto();
                }
            }

            return $tasks;
        } catch (Exception $e) {
            throw ClickUpErrorHandler::handleUnparseableResponse($e);
        }
    }

    /**
     * @throws ResourceNotFoundException
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     * @throws ExternalServiceUnavailableException
     */
    public function updateStatus(ApiKeyToken $token, string $taskId, string $status): void
    {
        $this->transport->put($token, "/task/{$taskId}", ['status' => $status]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildQuery(ClickUpTaskQueryParams $params): array
    {
        $query = [];

        if ($params->statuses !== []) {
            $query['statuses[]'] = $params->statuses;
        }

        if ($params->tags !== []) {
            $query['tags[]'] = $params->tags;
        }

        return $query;
    }
}
