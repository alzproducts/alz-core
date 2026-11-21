<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\Access\ApiKeyCipherInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Persistence\Models\QuickBooks\QuickBooksConnectionModel;

final readonly class EloquentQuickBooksConnectionRepository implements QuickBooksConnectionRepositoryInterface
{
    public function __construct(
        private EloquentGateway $eloquentGateway,
        private ApiKeyCipherInterface $cipher,
        private QuickBooksConfig $config,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws CorruptApiKeyException
     */
    public function find(): ?QuickBooksConnection
    {
        $model = $this->eloquentGateway->firstWhereAll(QuickBooksConnectionModel::class, $this->scope());

        if ($model === null) {
            return null;
        }

        return new QuickBooksConnection(
            refreshToken: $this->cipher->decrypt($model->encrypted_refresh_token),
            refreshTokenExpiresAt: $model->refresh_token_expires_at,
            refreshTokenHardExpiresAt: $model->refresh_token_hard_expires_at,
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws KeyEncryptionFailedException
     */
    public function save(QuickBooksConnection $connection): void
    {
        $this->eloquentGateway->upsertOne(
            modelClass: QuickBooksConnectionModel::class,
            attributes: [
                ...$this->scope(),
                'encrypted_refresh_token' => $this->cipher->encrypt($connection->refreshToken),
                'refresh_token_expires_at' => $connection->refreshTokenExpiresAt,
                'refresh_token_hard_expires_at' => $connection->refreshTokenHardExpiresAt,
            ],
            uniqueBy: ['environment'],
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function delete(): void
    {
        $this->eloquentGateway->deleteWhereAll(QuickBooksConnectionModel::class, $this->scope());
    }

    /**
     * @return array{environment: string}
     */
    private function scope(): array
    {
        return ['environment' => $this->config->environment->value];
    }
}
