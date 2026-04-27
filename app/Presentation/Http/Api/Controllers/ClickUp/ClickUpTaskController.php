<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers\ClickUp;

use App\Application\ClickUp\UseCases\CompleteClickUpTaskUseCase;
use App\Application\ClickUp\UseCases\GetMyClickUpTasksUseCase;
use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\MissingApiKeyException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Guid;
use App\Presentation\Http\Api\DTOs\ClickUp\GetMyClickUpTasksRequestDTO;
use App\Presentation\Http\Api\Resources\ClickUp\ClickUpTaskResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * ClickUp task endpoints for the authenticated user.
 *
 * GET  /api/clickup/tasks              — list tasks (cached 120s, ?refresh=1 to invalidate)
 * POST /api/clickup/tasks/{taskId}/complete — mark a task as complete
 *
 * @throws MissingApiKeyException
 * @throws AuthenticationExpiredException
 * @throws InvalidApiRequestException
 * @throws InvalidApiResponseException
 * @throws ResourceNotFoundException
 * @throws ExternalServiceUnavailableException
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws CorruptApiKeyException
 */
final readonly class ClickUpTaskController
{
    public function __construct(
        private GetMyClickUpTasksUseCase $getTasksUseCase,
        private CompleteClickUpTaskUseCase $completeTaskUseCase,
    ) {}

    /**
     * @throws MissingApiKeyException
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     */
    public function index(GetMyClickUpTasksRequestDTO $data, AuthenticatedUser $user): ResourceCollection
    {
        $tasks = $this->getTasksUseCase->execute(
            new Guid($user->id),
            $data->toQueryParams(),
        );

        return ClickUpTaskResource::collection($tasks);
    }

    /**
     * @throws MissingApiKeyException
     * @throws ResourceNotFoundException
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     */
    public function complete(string $taskId, AuthenticatedUser $user): JsonResponse
    {
        $this->completeTaskUseCase->execute(new Guid($user->id), $taskId);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
