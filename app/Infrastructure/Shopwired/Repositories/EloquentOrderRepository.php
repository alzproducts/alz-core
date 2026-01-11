<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\DatabaseClientInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Shopwired\ValueObjects\SaveManyResult;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderDiscount;
use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\DuplicateRecordException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Infrastructure\Shopwired\Mappers\OrderModelMapper;
use App\Infrastructure\Shopwired\Models\OrderDiscountModel;
use App\Infrastructure\Shopwired\Models\OrderModel;
use App\Infrastructure\Shopwired\Models\OrderProductModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Eloquent implementation of ShopWired order repository.
 *
 * Persists Domain Order entities to PostgreSQL using Eloquent models.
 * Uses upsert strategy based on ShopWired's external ID for idempotent sync.
 */
final readonly class EloquentOrderRepository implements OrderRepositoryInterface
{
    public function __construct(
        private DatabaseClientInterface $database,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function save(object $entity): void
    {
        /** @var Order $entity */
        $this->database->execute(function () use ($entity): void {
            DB::transaction(function () use ($entity): void {
                $model = $this->upsertOrder($entity);
                $this->syncProducts($model, $entity);
                $this->syncDiscounts($model, $entity);
            });
        });
    }

    /**
     * {@inheritDoc}
     */
    public function saveMany(array $entities): SaveManyResult
    {
        $succeeded = 0;
        $failed = 0;
        $failedReferences = [];

        foreach ($entities as $entity) {
            try {
                $this->save($entity);
                $succeeded++;
            } catch (DatabaseOperationFailedException|DuplicateRecordException|ExternalServiceUnavailableException $e) {
                $failed++;
                $failedReferences[] = $entity->id;

                Log::warning('Failed to save order', [
                    'external_id' => $entity->id,
                    'reference' => $entity->reference,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new SaveManyResult(
            succeeded: $succeeded,
            failed: $failed,
            failedReferences: $failedReferences,
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getByExternalId(int $externalId): Order
    {
        return $this->database->execute(static function () use ($externalId): Order {
            $model = OrderModel::query()
                ->where('external_id', $externalId)
                ->with(['products', 'discounts'])
                ->first();

            if ($model === null) {
                throw new ResourceNotFoundException('Database', 'Order', $externalId);
            }

            return EloquentOrderRepository::mapToDomain($model);
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
    public function getByReference(int $reference): Order
    {
        return $this->database->execute(static function () use ($reference): Order {
            $model = OrderModel::query()
                ->where('reference', $reference)
                ->with(['products', 'discounts'])
                ->first();

            if ($model === null) {
                throw new ResourceNotFoundException('Database', 'Order', $reference);
            }

            return EloquentOrderRepository::mapToDomain($model);
        });
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function existsByExternalId(int $externalId): bool
    {
        return $this->database->execute(
            static fn(): bool => OrderModel::query()
                ->where('external_id', $externalId)
                ->exists(),
        );
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
        $model = OrderModel::query()->updateOrCreate(
            ['external_id' => $order->id],
            $attributes,
        );

        return $model;
    }

    /**
     * Sync order products (delete removed, upsert existing).
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

        // Delete products no longer in order
        OrderProductModel::query()
            ->where('order_id', $model->id)
            ->whereNotIn('external_id', $currentIds)
            ->delete();

        // Upsert current products
        foreach ($order->products as $product) {
            $attributes = OrderProductModel::fromDomainAttributes($product);

            OrderProductModel::query()->updateOrCreate(
                [
                    'order_id' => $model->id,
                    'external_id' => $product->id,
                ],
                $attributes,
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

    // ─────────────────────────────────────────────────────────────────────────
    // Domain Mapping
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert Eloquent model to Domain Order.
     *
     * Uses model toDomain() methods for products/discounts, delegates to mapper for order.
     */
    private static function mapToDomain(OrderModel $model): Order
    {
        // Convert related models using their toDomain() methods
        $products = $model->products->map(
            static fn(OrderProductModel $m): OrderProduct => $m->toDomain(),
        )->all();

        /** @var array<int, OrderDiscount> $discounts */
        $discounts = $model->discounts->map(
            static fn(OrderDiscountModel $m) => $m->toDomain(),
        )->all();

        return OrderModelMapper::toDomain($model, $products, $discounts);
    }
}
