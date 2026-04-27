<?php

declare(strict_types=1);

namespace App\Application\ClickUp\UseCases;

use App\Application\Contracts\Access\UserApiKeyRepositoryInterface;
use App\Application\Contracts\ClickUp\ClickUpTasksCacheInterface;
use App\Application\Contracts\ClickUp\TasksClientInterface;
use App\Domain\Access\Enums\ThirdPartyService;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\MissingApiKeyException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;

final readonly class CompleteClickUpTaskUseCase
{
    public function __construct(
        private UserApiKeyRepositoryInterface $repository,
        private TasksClientInterface $tasksClient,
        private ClickUpTasksCacheInterface $tasksCache,
        private string $completeStatus,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws MissingApiKeyException When no API key has been configured
     * @throws ResourceNotFoundException When the task does not exist
     * @throws AuthenticationExpiredException When the API key is invalid or revoked
     * @throws InvalidApiRequestException When the request to ClickUp is malformed
     * @throws ExternalServiceUnavailableException When ClickUp is unavailable
     * @throws DatabaseOperationFailedException When reading from the DB fails
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException When the stored ciphertext cannot be decrypted
     */
    public function execute(Guid $userId, string $taskId): void
    {
        $this->logger->info('ClickUp task completion initiated', [
            'user_id' => $userId->value,
            'task_id' => $taskId,
        ]);

        $token = $this->repository->tokenForUser($userId, ThirdPartyService::ClickUp);
        if ($token === null) {
            throw new MissingApiKeyException(ThirdPartyService::ClickUp);
        }

        $this->tasksClient->completeTask($token, $taskId, $this->completeStatus);
        $this->tasksCache->forget($userId);

        $this->logger->info('ClickUp task marked complete and cache invalidated', [
            'user_id' => $userId->value,
            'task_id' => $taskId,
        ]);
    }
}
