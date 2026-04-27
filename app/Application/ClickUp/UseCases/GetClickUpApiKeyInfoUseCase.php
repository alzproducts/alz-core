<?php

declare(strict_types=1);

namespace App\Application\ClickUp\UseCases;

use App\Application\ClickUp\DTOs\ClickUpApiKeyMetaDTO;
use App\Application\ClickUp\Results\UserApiKeyMetaResult;
use App\Application\Contracts\Access\UserApiKeyRepositoryInterface;
use App\Application\Contracts\ClickUp\ClickUpUserCacheInterface;
use App\Domain\Access\Enums\ThirdPartyService;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;

final readonly class GetClickUpApiKeyInfoUseCase
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
     * @throws CorruptApiKeyException When the stored ciphertext cannot be decrypted
     */
    public function execute(Guid $userId): ClickUpApiKeyMetaDTO
    {
        $meta = $this->repository->metaForUser($userId, ThirdPartyService::ClickUp);

        if (! $meta->hasKey) {
            $this->logger->info('ClickUp API key info retrieved — no key configured', ['user_id' => $userId->value]);

            return new ClickUpApiKeyMetaDTO(hasKey: false, maskedKey: null, lastUsedAt: null, clickupUserEmail: null);
        }

        return $this->buildKeyPresentMeta($userId, $meta);
    }

    /**
     * @throws ExternalServiceUnavailableException
     */
    private function buildKeyPresentMeta(Guid $userId, UserApiKeyMetaResult $meta): ClickUpApiKeyMetaDTO
    {
        $cachedUser = $this->userCache->get($userId);

        $this->logger->info('ClickUp API key info retrieved', [
            'user_id' => $userId->value, 'has_cached_user' => $cachedUser !== null,
        ]);

        return new ClickUpApiKeyMetaDTO(
            hasKey: true,
            maskedKey: $meta->maskedKey,
            lastUsedAt: $meta->lastUsedAt,
            clickupUserEmail: $cachedUser?->email,
        );
    }
}
