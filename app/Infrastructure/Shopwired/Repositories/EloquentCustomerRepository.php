<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\Shopwired\CustomerRepositoryInterface;
use App\Domain\Customer\ValueObjects\Customer;
use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\DuplicateRecordException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Infrastructure\Shopwired\Mappers\CustomerModelMapper;
use App\Infrastructure\Shopwired\Models\CustomerModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent implementation of ShopWired customer repository.
 *
 * Persists Domain Customer entities to PostgreSQL using Eloquent models.
 * Uses upsert strategy based on ShopWired's external ID for idempotent sync.
 *
 * @extends AbstractShopwiredEloquentRepository<Customer>
 */
final class EloquentCustomerRepository extends AbstractShopwiredEloquentRepository implements CustomerRepositoryInterface
{
    /** @var class-string<CustomerModel> */
    private const string MODEL_CLASS = CustomerModel::class;

    private const string ENTITY_TYPE = 'Customer';

    /** @var list<string> */
    private const array EAGER_LOAD_RELATIONS = [];

    // ─────────────────────────────────────────────────────────────────────────
    // Interface Implementation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * @param Customer $entity
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function save(object $entity): void
    {
        $this->gateway->transact(function () use ($entity): void {
            $this->upsertCustomer($entity);
        }, attempts: 3);
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getByEmail(string $email): Customer
    {
        return $this->gateway->query(static function () use ($email): Customer {
            $model = self::MODEL_CLASS::query()
                ->where('email', $email)
                ->first();

            if ($model === null) {
                throw new ResourceNotFoundException('Database', self::ENTITY_TYPE, $email);
            }

            return CustomerModelMapper::fromModel($model);
        });
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findByEmail(string $email): ?Customer
    {
        return $this->gateway->query(static function () use ($email): ?Customer {
            $model = self::MODEL_CLASS::query()
                ->where('email', $email)
                ->first();

            if ($model === null) {
                return null;
            }

            return CustomerModelMapper::fromModel($model);
        });
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function existsByEmail(string $email): bool
    {
        return $this->gateway->query(
            static fn(): bool => self::MODEL_CLASS::query()
                ->where('email', $email)
                ->exists(),
        );
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
    protected function getEagerLoadRelations(): array
    {
        return self::EAGER_LOAD_RELATIONS;
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
     */
    protected function getEntityTypeName(): string
    {
        return self::ENTITY_TYPE;
    }

    /**
     * {@inheritDoc}
     */
    protected function mapModelToDomain(Model $model): Customer
    {
        /** @var CustomerModel $model */
        return CustomerModelMapper::fromModel($model);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Persistence Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Upsert customer record based on external_id.
     */
    private function upsertCustomer(Customer $customer): CustomerModel
    {
        $attributes = CustomerModelMapper::toModelAttributes($customer);

        /** @var CustomerModel $model */
        $model = self::MODEL_CLASS::query()->updateOrCreate(
            ['external_id' => $customer->id],
            $attributes,
        );

        return $model;
    }
}
