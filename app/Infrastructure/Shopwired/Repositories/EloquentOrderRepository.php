<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderAdminComment;
use App\Domain\Catalog\Order\ValueObjects\OrderDiscount;
use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use App\Domain\Catalog\Order\ValueObjects\OrderRefund;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use App\Infrastructure\Shopwired\Mappers\OrderModelMapper;
use App\Infrastructure\Shopwired\Models\OrderAdminCommentModel;
use App\Infrastructure\Shopwired\Models\OrderDiscountModel;
use App\Infrastructure\Shopwired\Models\OrderModel;
use App\Infrastructure\Shopwired\Models\OrderProductModel;
use App\Infrastructure\Shopwired\Models\OrderRefundModel;
use DateTimeImmutable;

/**
 * Eloquent implementation of ShopWired order repository.
 *
 * Persists Domain Order entities to PostgreSQL using Eloquent models.
 * Uses upsert strategy based on ShopWired's external ID for idempotent sync.
 *
 * @extends AbstractEloquentRepository<Order>
 */
final class EloquentOrderRepository extends AbstractEloquentRepository implements OrderRepositoryInterface
{
    /** @var class-string<OrderModel> */
    private const string MODEL_CLASS = OrderModel::class;

    /** @var list<string> */
    private const array EAGER_LOAD_RELATIONS = ['products', 'discounts', 'refunds', 'adminComments'];

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
        $this->eloquentGateway->transact(function () use ($entity): void {
            // 1. Upsert order (single INSERT ON CONFLICT query)
            $this->eloquentGateway->upsertOne(
                modelClass: self::MODEL_CLASS,
                attributes: [
                    'external_id' => $entity->id,
                    ...OrderModelMapper::toModelAttributes($entity),
                ],
                uniqueBy: ['external_id'],
            );

            // 2. Fetch order UUID for FK relationships on child tables
            /** @var string $orderUuid */
            $orderUuid = self::MODEL_CLASS::query()
                ->where('external_id', $entity->id)
                ->value('id');

            // 3. Sync child tables
            $this->syncProducts($orderUuid, $entity);
            $this->syncDiscounts($orderUuid, $entity);
            $this->syncRefunds($orderUuid, $entity);
            $this->syncAdminComments($orderUuid, $entity);
        }, attempts: 3);
    }

    /**
     * {@inheritDoc}
     *
     * When multiple orders share the same reference (edited orders in ShopWired),
     * this method returns the "active" order using this priority:
     * 1. Non-cancelled order (if exactly one exists)
     * 2. Highest external_id (most recent ShopWired order ID)
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getByReference(int $reference): Order
    {
        return $this->eloquentGateway->query(function () use ($reference): Order {
            $orders = self::MODEL_CLASS::query()
                ->where('reference', $reference)
                ->with(self::EAGER_LOAD_RELATIONS)
                ->get();

            if ($orders->isEmpty()) {
                throw new ResourceNotFoundException('Database', $this->getEntityTypeName(), $reference);
            }

            // Single order - return directly
            if ($orders->count() === 1) {
                /** @var OrderModel $model */
                $model = $orders->first();

                return OrderModelMapper::fromModelWithRelations($model);
            }

            // Multiple orders: prefer non-cancelled, then highest external_id
            // Sort descending by external_id, then partition by cancelled status
            $nonCancelled = $orders->filter(
                static fn(OrderModel $o): bool => $o->status_type !== 'cancelled',
            );

            // Return first non-cancelled (highest external_id), or highest cancelled
            /** @var OrderModel $selected */
            $selected = $nonCancelled->isNotEmpty()
                ? $nonCancelled->sortByDesc('external_id')->first()
                : $orders->sortByDesc('external_id')->first();

            return OrderModelMapper::fromModelWithRelations($selected);
        });
    }

    /**
     * {@inheritDoc}
     *
     * @return list<Order>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getOrdersInDateRange(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->eloquentGateway->query(static function () use ($from, $to): array {
            $models = self::MODEL_CLASS::query()
                ->whereBetween('order_placed_at', [$from, $to])
                ->with(self::EAGER_LOAD_RELATIONS)
                ->orderBy('order_placed_at')
                ->get();

            return \array_values(
                $models
                    ->map(static fn(OrderModel $model): Order => OrderModelMapper::fromModelWithRelations($model))
                    ->all(),
            );
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

    // ─────────────────────────────────────────────────────────────────────────
    // Persistence Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sync order products (replace all on each sync).
     *
     * Products have no stable unique line item ID - ShopWired's external_id is the
     * PRODUCT ID, not a line item identifier. Multiple line items can share the same
     * external_id when ordering product variations (e.g., "Magiplug - Basin" +
     * "Magiplug - Kitchen Sink" both use the Magiplug product ID).
     *
     * Delete all and bulk insert fresh - same pattern as discounts/refunds/comments.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function syncProducts(string $orderUuid, Order $order): void
    {
        // 1. Delete existing products
        $this->eloquentGateway->deleteWhere(
            modelClass: OrderProductModel::class,
            column: 'order_external_id',
            value: $order->id,
        );

        // 2. Bulk insert fresh products (single query vs N queries)
        if ($order->products !== null && $order->products !== []) {
            /** @var list<array<string, mixed>> $rows */
            $rows = \array_values(\array_map(
                static fn(OrderProduct $p): array => [
                    'order_id' => $orderUuid,
                    'order_external_id' => $p->orderExternalId,
                    'external_id' => $p->id,
                    ...OrderProductModel::fromDomainAttributes($p),
                ],
                $order->products,
            ));

            $this->eloquentGateway->insertMany(
                modelClass: OrderProductModel::class,
                rows: $rows,
            );
        }
    }

    /**
     * Sync order discounts (replace all on each sync).
     *
     * Discounts have no stable ID - delete all and bulk insert fresh.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function syncDiscounts(string $orderUuid, Order $order): void
    {
        // 1. Delete existing discounts
        $this->eloquentGateway->deleteWhere(
            modelClass: OrderDiscountModel::class,
            column: 'order_external_id',
            value: $order->id,
        );

        // 2. Bulk insert fresh discounts (single query vs N queries)
        if ($order->discounts !== []) {
            /** @var list<array<string, mixed>> $rows */
            $rows = \array_values(\array_map(
                static fn(OrderDiscount $discount): array => [
                    'order_id' => $orderUuid,
                    'order_external_id' => $order->id,
                    ...OrderDiscountModel::fromDomainAttributes($discount),
                ],
                $order->discounts,
            ));

            $this->eloquentGateway->insertMany(
                modelClass: OrderDiscountModel::class,
                rows: $rows,
            );
        }
    }

    /**
     * Sync order refunds (replace all on each sync).
     *
     * Refunds have no stable ID - delete all and bulk insert fresh.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function syncRefunds(string $orderUuid, Order $order): void
    {
        // 1. Delete existing refunds
        $this->eloquentGateway->deleteWhere(
            modelClass: OrderRefundModel::class,
            column: 'order_external_id',
            value: $order->id,
        );

        // 2. Bulk insert fresh refunds (single query vs N queries)
        if ($order->refunds !== []) {
            /** @var list<array<string, mixed>> $rows */
            $rows = \array_values(\array_map(
                static fn(OrderRefund $refund): array => [
                    'order_id' => $orderUuid,
                    'order_external_id' => $order->id,
                    ...OrderRefundModel::fromDomainAttributes($refund),
                ],
                $order->refunds,
            ));

            $this->eloquentGateway->insertMany(
                modelClass: OrderRefundModel::class,
                rows: $rows,
            );
        }
    }

    /**
     * Sync order admin comments (replace all on each sync).
     *
     * Admin comments have no stable ID - delete all and bulk insert fresh.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function syncAdminComments(string $orderUuid, Order $order): void
    {
        // 1. Delete existing admin comments
        $this->eloquentGateway->deleteWhere(
            modelClass: OrderAdminCommentModel::class,
            column: 'order_external_id',
            value: $order->id,
        );

        // 2. Bulk insert fresh admin comments (single query vs N queries)
        if ($order->adminComments !== []) {
            /** @var list<array<string, mixed>> $rows */
            $rows = \array_values(\array_map(
                static fn(OrderAdminComment $comment): array => [
                    'order_id' => $orderUuid,
                    'order_external_id' => $order->id,
                    ...OrderAdminCommentModel::fromDomainAttributes($comment),
                ],
                $order->adminComments,
            ));

            $this->eloquentGateway->insertMany(
                modelClass: OrderAdminCommentModel::class,
                rows: $rows,
            );
        }
    }
}
