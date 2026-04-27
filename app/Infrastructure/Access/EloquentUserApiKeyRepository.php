<?php

declare(strict_types=1);

namespace App\Infrastructure\Access;

use App\Application\ClickUp\Results\UserApiKeyMetaResult;
use App\Application\Contracts\Access\ApiKeyCipherInterface;
use App\Application\Contracts\Access\UserApiKeyRepositoryInterface;
use App\Domain\Access\Enums\ThirdPartyService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Persistence\Models\Access\UserApiKeyModel;
use DateTimeImmutable;
use DateTimeInterface;

final readonly class EloquentUserApiKeyRepository implements UserApiKeyRepositoryInterface
{
    public function __construct(
        private EloquentGateway $eloquentGateway,
        private ApiKeyCipherInterface $cipher,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws CorruptApiKeyException
     */
    public function tokenForUser(Guid $userId, ThirdPartyService $service): ?ApiKeyToken
    {
        $model = $this->findModel($userId, $service);

        if ($model === null) {
            return null;
        }

        return $this->cipher->decrypt($model->encrypted_key);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws CorruptApiKeyException
     */
    public function metaForUser(Guid $userId, ThirdPartyService $service): UserApiKeyMetaResult
    {
        $model = $this->findModel($userId, $service);

        if ($model === null) {
            return new UserApiKeyMetaResult(hasKey: false, maskedKey: null, lastUsedAt: null);
        }

        $masked = $this->cipher->decrypt($model->encrypted_key)->masked();
        $lastUsedAt = $model->last_used_at instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($model->last_used_at)
            : null;

        return new UserApiKeyMetaResult(hasKey: true, maskedKey: $masked, lastUsedAt: $lastUsedAt);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws KeyEncryptionFailedException
     */
    public function save(Guid $userId, ThirdPartyService $service, ApiKeyToken $token): void
    {
        $encrypted = $this->cipher->encrypt($token);

        $this->eloquentGateway->query(static function () use ($userId, $service, $encrypted): void {
            UserApiKeyModel::query()->updateOrCreate(
                ['user_id' => $userId->value, 'service' => $service->value],
                ['encrypted_key' => $encrypted, 'is_valid' => true],
            );
        });
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function delete(Guid $userId, ThirdPartyService $service): void
    {
        $this->eloquentGateway->query(static function () use ($userId, $service): void {
            UserApiKeyModel::query()
                ->where('user_id', $userId->value)
                ->where('service', $service->value)
                ->delete();
        });
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function findModel(Guid $userId, ThirdPartyService $service): ?UserApiKeyModel
    {
        /** @var UserApiKeyModel|null */
        return $this->eloquentGateway->query(
            static fn(): ?UserApiKeyModel => UserApiKeyModel::query()
                ->where('user_id', $userId->value)
                ->where('service', $service->value)
                ->where('is_valid', true)
                ->first(),
        );
    }
}
