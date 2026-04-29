<?php

declare(strict_types=1);

namespace App\Application\ClickUp\UseCases;

use App\Application\Contracts\Access\UserApiKeyRepositoryInterface;
use App\Application\Contracts\ClickUp\ClickUpUserCacheInterface;
use App\Application\Contracts\ClickUp\UsersClientInterface;
use App\Domain\Access\Enums\ThirdPartyService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;

final readonly class SaveClickUpApiKeyUseCase
{
    public function __construct(
        private UsersClientInterface $usersClient,
        private UserApiKeyRepositoryInterface $repository,
        private ClickUpUserCacheInterface $userCache,
        private LoggerInterface $logger,
    ) {}

    /**
     * Validate the token against ClickUp, then persist it (unless dry run).
     *
     * @throws AuthenticationExpiredException When the API key is invalid or revoked
     * @throws InvalidApiRequestException When the request to ClickUp is malformed
     * @throws InvalidApiResponseException When the ClickUp response cannot be parsed
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException When ClickUp is unavailable
     * @throws DatabaseOperationFailedException When saving to the DB fails
     * @throws DuplicateRecordException
     * @throws KeyEncryptionFailedException When local encryption fails
     */
    public function execute(Guid $userId, ApiKeyToken $token, bool $dryRun = false): void
    {
        $this->logger->info('ClickUp API key save initiated', ['user_id' => $userId->value, 'dry_run' => $dryRun]);

        $userData = $this->usersClient->getUser($token);

        if ($dryRun) {
            $this->logger->info('ClickUp API key validated (dry run — not persisted)', [
                'user_id' => $userId->value, 'clickup_user_email' => $userData->email,
            ]);

            return;
        }

        $this->repository->save($userId, ThirdPartyService::ClickUp, $token);
        $this->userCache->put($userId, $userData);
        $this->logger->info('ClickUp API key saved and user cache updated', [
            'user_id' => $userId->value, 'clickup_user_email' => $userData->email,
        ]);
    }
}
