<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderAdminComment;
use App\Domain\Catalog\Order\ValueObjects\OrderDiscount;
use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use App\Domain\Catalog\Order\ValueObjects\OrderRefund;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use App\Infrastructure\Shopwired\Mappers\OrderModelMapper;
use App\Infrastructure\Shopwired\Models\OrderAdminCommentModel;
use App\Infrastructure\Shopwired\Models\OrderDiscountModel;
use App\Infrastructure\Shopwired\Models\OrderModel;
use App\Infrastructure\Shopwired\Models\OrderProductModel;
use App\Infrastructure\Shopwired\Models\OrderRefundModel;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

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

            // 3. Sync child tables (null = not provided by caller, skip sync)
            $this->syncProducts($orderUuid, $entity);

            if ($entity->discounts !== null) {
                $this->syncDiscounts($orderUuid, $entity);
            }

            if ($entity->refunds !== null) {
                $this->syncRefunds($orderUuid, $entity);
            }

            if ($entity->adminComments !== null) {
                $this->syncAdminComments($orderUuid, $entity);
            }
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
            /** @var Collection<int, OrderModel> $orders */
            $orders = self::MODEL_CLASS::query()
                ->where('reference', $reference)
                ->with(self::EAGER_LOAD_RELATIONS)
                ->get();

            if ($orders->isEmpty()) {
                throw new ResourceNotFoundException('Database', $this->getEntityTypeName(), $reference);
            }

            $selected = self::selectPreferredOrder($orders);

            return OrderModelMapper::fromModelWithRelations($selected);
        });
    }

    /**
     * {@inheritDoc}
     *
     * Returns deduplicated orders (one per reference) using the orders_deduplicated view.
     * Excludes orders from test customer emails (configured in shopwired.excluded_customer_emails).
     *
     * @return list<Order>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     *
     * @see shopwired.orders_deduplicated - View handling deduplication
     */
    public function getOrdersInDateRange(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->eloquentGateway->query(static function () use ($from, $to): array {
            $query = self::MODEL_CLASS::query()
                ->from('shopwired.orders_deduplicated')
                ->whereBetween('order_placed_at', [$from, $to])
                ->with(self::EAGER_LOAD_RELATIONS)
                ->orderBy('order_placed_at');

            /** @var list<string> $excludedEmails */
            $excludedEmails = \config('shopwired.excluded_customer_emails', []);

            if ($excludedEmails !== []) {
                $query->whereNotIn('billing_email', $excludedEmails);
            }

            return \array_values(
                $query->get()
                    ->map(static fn(OrderModel $model): Order => OrderModelMapper::fromModelWithRelations($model))
                    ->all(),
            );
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
    public function getAllOrdersInDateRange(DateTimeImmutable $from, DateTimeImmutable $to): array
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
    public function updateStatus(IntId $externalId, OrderStatus $status): void
    {
        $affected = $this->eloquentGateway->updateWhere(
            modelClass: self::MODEL_CLASS,
            column: 'external_id',
            value: $externalId->value,
            data: [
                'status_id' => $status->id,
                'status_name' => $status->name->value,
                'status_type' => $status->type,
                'status_sort_order' => $status->sortOrder,
            ],
        );

        if ($affected === 0) {
            throw new ResourceNotFoundException('Database', $this->getEntityTypeName(), $externalId->value);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RuntimeException If fillForInsert returns unexpected result (programming error)
     */
    public function addRefund(IntId $orderExternalId, OrderRefund $refund): void
    {
        $this->eloquentGateway->query(function () use ($orderExternalId, $refund): void {
            /** @var string|null $orderUuid */
            $orderUuid = self::MODEL_CLASS::query()
                ->where('external_id', $orderExternalId->value)
                ->value('id');

            if ($orderUuid === null) {
                throw new ResourceNotFoundException('Database', $this->getEntityTypeName(), $orderExternalId->value);
            }

            $this->eloquentGateway->insertOne(
                modelClass: OrderRefundModel::class,
                attributes: [
                    'order_id' => $orderUuid,
                    'order_external_id' => $orderExternalId->value,
                    ...OrderRefundModel::fromDomainAttributes($refund),
                ],
            );
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

    protected function getEntityIdentifier(object $entity): int
    {
        /** @var Order $entity */
        return $entity->id;
    }

    /**
     * {@inheritDoc}
     *
     * @param Order $entity
     */
    protected function entityToAttributes(object $entity): array
    {
        return [
            'external_id' => $entity->id,
            ...OrderModelMapper::toModelAttributes($entity),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getUpsertKeys(): array
    {
        return ['external_id'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Query Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Select the preferred order from a collection sharing the same reference.
     *
     * When ShopWired orders are "edited", a new order is created with the same
     * customer-facing reference but a new external_id. The original is cancelled.
     *
     * Selection priority:
     * 1. Non-cancelled orders take priority over cancelled ones
     * 2. Among same-status orders, highest external_id wins (most recent)
     *
     * Note: This method is kept for getByReference() which queries the raw orders table
     * for performance (single-reference lookup is faster than using the view).
     * For bulk queries, use the shopwired.orders_deduplicated view instead.
     *
     * @param Collection<int, OrderModel> $orders Non-empty collection of orders with same reference
     *
     * @see shopwired.orders_deduplicated - View-based deduplication for bulk queries
     */
    private static function selectPreferredOrder(Collection $orders): OrderModel
    {
        // Filter to non-cancelled orders
        $nonCancelled = $orders->filter(
            static fn(OrderModel $o): bool => $o->status_type !== 'cancelled',
        );

        // Return best non-cancelled, or best cancelled if all are cancelled
        /** @var OrderModel */
        return $nonCancelled->isNotEmpty()
            ? $nonCancelled->sortByDesc('external_id')->first()
            : $orders->sortByDesc('external_id')->first();
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
        if ($order->discounts !== null && $order->discounts !== []) {
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
        if ($order->refunds !== null && $order->refunds !== []) {
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
        if ($order->adminComments !== null && $order->adminComments !== []) {
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
