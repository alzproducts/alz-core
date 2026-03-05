<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\Shopwired\CustomerRepositoryInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Customer\ValueObjects\Customer;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use App\Infrastructure\Shopwired\Mappers\CustomerModelMapper;
use App\Infrastructure\Shopwired\Models\CustomerModel;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Eloquent implementation of ShopWired customer repository.
 *
 * Persists Domain Customer entities to PostgreSQL using Eloquent models.
 * Uses upsert strategy based on ShopWired's external ID for idempotent sync.
 *
 * @extends AbstractEloquentRepository<Customer>
 */
final class EloquentCustomerRepository extends AbstractEloquentRepository implements CustomerRepositoryInterface
{
    /** @var class-string<CustomerModel> */
    private const string MODEL_CLASS = CustomerModel::class;

    // ─────────────────────────────────────────────────────────────────────────
    // Interface Implementation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getTradeStatusByIds(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        return $this->eloquentGateway->query(static function () use ($customerIds): array {
            /** @var array<int, bool> */
            return CustomerModel::query()
                ->whereIn('external_id', $customerIds)
                ->pluck('is_trade', 'external_id')
                ->all();
        });
    }

    /**
     * {@inheritDoc}
     *
     * @param list<Customer> $customers
     */
    public function saveCustomersBulk(array $customers, int $batchSize = 500): SaveManyResult
    {
        return $this->saveManyBulk(
            entities: $customers,
            entityToAttributes: static fn(Customer $customer): array => [
                'external_id' => $customer->id,
                ...CustomerModelMapper::toModelAttributes($customer),
            ],
            upsertKeys: ['external_id'],
            batchSize: $batchSize,
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function saveFromWebhook(Customer $customer, DateTimeImmutable $webhookAt): void
    {
        $this->eloquentGateway->upsertOne(
            modelClass: self::MODEL_CLASS,
            attributes: [
                'external_id' => $customer->id,
                ...CustomerModelMapper::toModelAttributes($customer),
                'shopwired_webhook_at' => $webhookAt,
            ],
            uniqueBy: ['external_id'],
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Webhook Partial Update Methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function deleteByExternalId(IntId $externalId): void
    {
        $deleted = $this->eloquentGateway->deleteWhere(
            modelClass: self::MODEL_CLASS,
            column: 'external_id',
            value: $externalId->value,
        );

        if ($deleted === 0) {
            throw new ResourceNotFoundException('Database', $this->getEntityTypeName(), $externalId->value);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getWebhookTimestamp(IntId $externalId): ?DateTimeImmutable
    {
        return $this->eloquentGateway->query(static function () use ($externalId): ?DateTimeImmutable {
            /** @var string|null $timestamp */
            $timestamp = self::MODEL_CLASS::query()
                ->where('external_id', $externalId->value)
                ->value('shopwired_webhook_at');

            return $timestamp !== null ? CarbonImmutable::parse($timestamp)->toDateTimeImmutable() : null;
        });
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function updateWebhookTimestamp(IntId $externalId, DateTimeImmutable $timestamp): void
    {
        $affected = $this->eloquentGateway->updateWhere(
            modelClass: self::MODEL_CLASS,
            column: 'external_id',
            value: $externalId->value,
            data: ['shopwired_webhook_at' => $timestamp],
        );

        if ($affected === 0) {
            throw new ResourceNotFoundException('Database', $this->getEntityTypeName(), $externalId->value);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Abstract Method Implementations
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     */
    protected function getModelClass(): string
    {
        return self::MODEL_CLASS;
    }

    /**
     * {@inheritDoc}
     */
    protected function getEntityIdentifier(object $entity): int
    {
        /** @var Customer $entity */
        return $entity->id;
    }

    /**
     * {@inheritDoc}
     *
     * @param Customer $entity
     */
    protected function entityToAttributes(object $entity): array
    {
        return [
            'external_id' => $entity->id,
            ...CustomerModelMapper::toModelAttributes($entity),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getUpsertKeys(): array
    {
        return ['external_id'];
    }
}
