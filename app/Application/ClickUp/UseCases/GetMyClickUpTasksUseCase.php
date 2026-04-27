<?php

declare(strict_types=1);

namespace App\Application\ClickUp\UseCases;

use App\Application\ClickUp\DTOs\ClickUpTaskDataDTO;
use App\Application\ClickUp\Queries\ClickUpTaskQueryParams;
use App\Application\Contracts\Access\UserApiKeyRepositoryInterface;
use App\Application\Contracts\ClickUp\TasksClientInterface;
use App\Domain\Access\Enums\ThirdPartyService;
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
use Psr\Log\LoggerInterface;

final readonly class GetMyClickUpTasksUseCase
{
    public function __construct(
        private UserApiKeyRepositoryInterface $repository,
        private TasksClientInterface $tasksClient,
        private string $listId,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<ClickUpTaskDataDTO>
     *
     * @throws MissingApiKeyException When no API key has been configured
     * @throws AuthenticationExpiredException When the API key is invalid or revoked
     * @throws InvalidApiRequestException When the request to ClickUp is malformed
     * @throws InvalidApiResponseException When the ClickUp response cannot be parsed
     * @throws ResourceNotFoundException When the configured list does not exist
     * @throws ExternalServiceUnavailableException When ClickUp is unavailable
     * @throws DatabaseOperationFailedException When reading from the DB fails
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException When the stored ciphertext cannot be decrypted
     */
    public function execute(Guid $userId, ClickUpTaskQueryParams $params): array
    {
        $token = $this->repository->tokenForUser($userId, ThirdPartyService::ClickUp);
        if ($token === null) {
            throw new MissingApiKeyException(ThirdPartyService::ClickUp);
        }

        $tasks = $this->tasksClient->getTasksForList($token, $this->listId, $params);

        $this->logger->info('ClickUp tasks fetched', [
            'user_id' => $userId->value,
            'task_count' => \count($tasks),
            'list_id' => $this->listId,
        ]);

        return $tasks;
    }
}
