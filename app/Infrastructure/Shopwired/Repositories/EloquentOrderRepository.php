<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\DuplicateRecordException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Infrastructure\Shopwired\Mappers\OrderModelMapper;
use App\Infrastructure\Shopwired\Models\OrderDiscountModel;
use App\Infrastructure\Shopwired\Models\OrderModel;
use App\Infrastructure\Shopwired\Models\OrderProductModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent implementation of ShopWired order repository.
 *
 * Persists Domain Order entities to PostgreSQL using Eloquent models.
 * Uses upsert strategy based on ShopWired's external ID for idempotent sync.
 *
 * @extends AbstractShopwiredEloquentRepository<Order>
 */
final class EloquentOrderRepository extends AbstractShopwiredEloquentRepository implements OrderRepositoryInterface
{
    /** @var class-string<OrderModel> */
    private const string MODEL_CLASS = OrderModel::class;

    private const string ENTITY_TYPE = 'Order';

    /** @var list<string> */
    private const array EAGER_LOAD_RELATIONS = ['products', 'discounts'];

    // ─────────────────────────────────────────────────────────────────────────
    // Interface Implementation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * @param Order $entity
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function save(object $entity): void
    {
        $this->gateway->transact(function () use ($entity): void {
            $model = $this->upsertOrder($entity);
            $this->syncProducts($model, $entity);
            $this->syncDiscounts($model, $entity);
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
    public function getByReference(int $reference): Order
    {
        return $this->gateway->query(static function () use ($reference): Order {
            $model = self::MODEL_CLASS::query()
                ->where('reference', $reference)
                ->with(self::EAGER_LOAD_RELATIONS)
                ->first();

            if ($model === null) {
                throw new ResourceNotFoundException('Database', self::ENTITY_TYPE, $reference);
            }

            return OrderModelMapper::fromModelWithRelations($model);
        });
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
        /** @var Order $entity */
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
    protected function mapModelToDomain(Model $model): Order
    {
        /** @var OrderModel $model */
        return OrderModelMapper::fromModelWithRelations($model);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Persistence Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Upsert order record based on external_id.
     */
    private function upsertOrder(Order $order): OrderModel
    {
        $attributes = OrderModelMapper::toModelAttributes($order);

        /** @var OrderModel $model */
        $model = self::MODEL_CLASS::query()->updateOrCreate(
            ['external_id' => $order->id],
            $attributes,
        );

        return $model;
    }

    /**
     * Sync order products (delete removed, upsert existing).
     *
     * Uses stable ShopWired IDs (order_external_id, external_id) for upsert lookup,
     * ensuring sync works correctly even if internal UUIDs change.
     */
    private function syncProducts(OrderModel $model, Order $order): void
    {
        if ($order->products === null) {
            return;
        }

        $currentIds = \array_map(
            static fn(OrderProduct $p): int => $p->id,
            $order->products,
        );

        // Delete products no longer in order (using stable external IDs)
        OrderProductModel::query()
            ->where('order_external_id', $order->id)
            ->whereNotIn('external_id', $currentIds)
            ->delete();

        // Upsert current products using stable external IDs for lookup
        foreach ($order->products as $product) {
            $attributes = OrderProductModel::fromDomainAttributes($product);

            OrderProductModel::query()->updateOrCreate(
                [
                    'order_external_id' => $product->orderExternalId,
                    'external_id' => $product->id,
                ],
                $attributes + ['order_id' => $model->id],
            );
        }
    }

    /**
     * Sync order discounts (replace all on each sync).
     */
    private function syncDiscounts(OrderModel $model, Order $order): void
    {
        // Discounts have no stable ID - replace all on sync
        OrderDiscountModel::query()
            ->where('order_id', $model->id)
            ->delete();

        foreach ($order->discounts as $discount) {
            $attributes = OrderDiscountModel::fromDomainAttributes($discount);
            $attributes['order_id'] = $model->id;

            OrderDiscountModel::query()->create($attributes);
        }
    }
}
