<?php

declare(strict_types=1);

namespace App\Application\ClickUp\UseCases;

use App\Application\Contracts\Access\UserApiKeyRepositoryInterface;
use App\Application\Contracts\ClickUp\ClickUpUserCacheInterface;
use App\Domain\Access\Enums\ThirdPartyService;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;

final readonly class DeleteClickUpApiKeyUseCase
{
    public function __construct(
        private UserApiKeyRepositoryInterface $repository,
        private ClickUpUserCacheInterface $userCache,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(Guid $userId): void
    {
        $this->logger->info('ClickUp API key deletion initiated', [
            'user_id' => $userId->value,
        ]);

        $this->repository->delete($userId, ThirdPartyService::ClickUp);
        $this->userCache->forget($userId);

        $this->logger->info('ClickUp API key deleted and user cache cleared', [
            'user_id' => $userId->value,
        ]);
    }
}
