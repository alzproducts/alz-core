<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\DatabaseClientInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Shopwired\ValueObjects\SaveManyResult;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderAddress;
use App\Domain\Catalog\Order\ValueObjects\OrderCustomer;
use App\Domain\Catalog\Order\ValueObjects\OrderDiscount;
use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use App\Domain\Catalog\Order\ValueObjects\OrderShipping;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\ValueObjects\PaymentMethod;
use App\Domain\Catalog\Order\ValueObjects\ProductVariation;
use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\DuplicateRecordException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Infrastructure\Shopwired\Mappers\StatusTypeToLifecycleMapper;
use App\Infrastructure\Shopwired\Models\OrderDiscountModel;
use App\Infrastructure\Shopwired\Models\OrderModel;
use App\Infrastructure\Shopwired\Models\OrderProductModel;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
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
        return $this->database->execute(function () use ($externalId): Order {
            $model = OrderModel::query()
                ->where('external_id', $externalId)
                ->with(['products', 'discounts'])
                ->first();

            if ($model === null) {
                throw new ResourceNotFoundException('Database', 'Order', $externalId);
            }

            return $this->toDomain($model);
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
        return $this->database->execute(function () use ($reference): Order {
            $model = OrderModel::query()
                ->where('reference', $reference)
                ->with(['products', 'discounts'])
                ->first();

            if ($model === null) {
                throw new ResourceNotFoundException('Database', 'Order', $reference);
            }

            return $this->toDomain($model);
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
        $attributes = self::toModelAttributes($order);

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
            OrderProductModel::query()->updateOrCreate(
                [
                    'order_id' => $model->id,
                    'external_id' => $product->id,
                ],
                [
                    'title' => $product->title,
                    'sku' => $product->sku,
                    'price' => $product->price,
                    'price_vat' => $product->priceVat,
                    'total' => $product->total,
                    'total_vat' => $product->totalVat,
                    'original_price' => $product->originalPrice,
                    'cost_price' => $product->costPrice,
                    'quantity' => $product->quantity,
                    'vat_rate' => $product->vatRate,
                    'comments' => $product->comments,
                    'variation' => \array_map(
                        static fn(ProductVariation $v): array => $v->toArray(),
                        $product->variation,
                    ),
                    'custom_fields' => $product->customFields,
                ],
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
            OrderDiscountModel::query()->create([
                'order_id' => $model->id,
                'name' => $discount->name,
                'value' => $discount->value,
                'type' => $discount->type,
                'code' => $discount->code,
                'voucher_id' => $discount->voucherId,
                'offer_id' => $discount->offerId,
            ]);
        }
    }

    /**
     * Convert Domain Order to Eloquent model attributes.
     *
     * @return array<string, mixed>
     */
    private static function toModelAttributes(Order $order): array
    {
        return [
            'reference' => $order->reference,
            'total' => $order->total,
            'sub_total' => $order->subTotal,
            'shipping_total' => $order->shippingTotal,
            'status_id' => 0, // Status ID not available from Domain - stored for future use
            'status_name' => $order->status->name->value,
            'status_type' => $order->status->type,
            'lifecycle_status' => StatusTypeToLifecycleMapper::toLifecycle($order->status->name)->value,
            'customer_id' => $order->customer->id,
            'customer_type' => $order->customer->type,
            'customer_date_of_birth' => $order->customer->dateOfBirth,
            'customer_device_info' => $order->customer->deviceInfo,
            'billing_name' => $order->billingAddress->name,
            'billing_email' => $order->billingAddress->emailAddress,
            'billing_telephone' => $order->billingAddress->telephone,
            'billing_company' => $order->billingAddress->companyName,
            'billing_address_line1' => $order->billingAddress->addressLine1,
            'billing_address_line2' => $order->billingAddress->addressLine2,
            'billing_address_line3' => $order->billingAddress->addressLine3,
            'billing_city' => $order->billingAddress->city,
            'billing_province' => $order->billingAddress->province,
            'billing_state' => $order->billingAddress->state,
            'billing_postcode' => $order->billingAddress->postcode,
            'billing_country' => $order->billingAddress->country,
            'delivery_name' => $order->shippingAddress->name,
            'delivery_email' => $order->shippingAddress->emailAddress,
            'delivery_telephone' => $order->shippingAddress->telephone,
            'delivery_company' => $order->shippingAddress->companyName,
            'delivery_address_line1' => $order->shippingAddress->addressLine1,
            'delivery_address_line2' => $order->shippingAddress->addressLine2,
            'delivery_address_line3' => $order->shippingAddress->addressLine3,
            'delivery_city' => $order->shippingAddress->city,
            'delivery_province' => $order->shippingAddress->province,
            'delivery_state' => $order->shippingAddress->state,
            'delivery_postcode' => $order->shippingAddress->postcode,
            'delivery_country' => $order->shippingAddress->country,
            'shipping_method' => $order->shipping?->name,
            'shipping_cost' => $order->shipping?->value,
            'shipping_vat_rate' => $order->shipping?->vatRate,
            'payment_method' => $order->paymentMethod->value,
            'marketing' => $order->marketing,
            'has_vat_relief' => $order->hasVatRelief,
            'comments' => $order->comments,
            'custom_fields' => $order->customFields,
            'synced_at' => new DateTimeImmutable(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Domain Mapping
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert Eloquent model to Domain Order.
     */
    private function toDomain(OrderModel $model): Order
    {
        $statusType = OrderStatusType::tryFrom($model->status_name);
        if ($statusType === null) {
            Log::error('Unknown OrderStatusType in database - possible API change', [
                'external_id' => $model->external_id,
                'status_name' => $model->status_name,
            ]);
            $statusType = OrderStatusType::Processing;
        }

        return new Order(
            id: $model->external_id,
            reference: $model->reference,
            total: $model->total,
            subTotal: $model->sub_total,
            shippingTotal: $model->shipping_total,
            paymentMethod: PaymentMethod::tryFrom($model->payment_method) ?? PaymentMethod::Unknown,
            comments: $model->comments ?? '',
            marketing: $model->marketing,
            hasVatRelief: $model->has_vat_relief,
            status: new OrderStatus(
                name: $statusType,
                type: $model->status_type,
            ),
            customer: new OrderCustomer(
                id: $model->customer_id,
                type: $model->customer_type,
                dateOfBirth: $model->customer_date_of_birth,
                deviceInfo: $model->customer_device_info ?? [],
            ),
            shipping: self::buildShipping($model),
            billingAddress: new OrderAddress(
                name: $model->billing_name,
                emailAddress: $model->billing_email,
                telephone: $model->billing_telephone,
                companyName: $model->billing_company,
                addressLine1: $model->billing_address_line1,
                addressLine2: $model->billing_address_line2,
                addressLine3: $model->billing_address_line3,
                city: $model->billing_city,
                province: $model->billing_province,
                state: $model->billing_state,
                postcode: $model->billing_postcode,
                country: $model->billing_country,
            ),
            shippingAddress: new OrderAddress(
                name: $model->delivery_name,
                emailAddress: $model->delivery_email,
                telephone: $model->delivery_telephone,
                companyName: $model->delivery_company,
                addressLine1: $model->delivery_address_line1,
                addressLine2: $model->delivery_address_line2,
                addressLine3: $model->delivery_address_line3,
                city: $model->delivery_city,
                province: $model->delivery_province,
                state: $model->delivery_state,
                postcode: $model->delivery_postcode,
                country: $model->delivery_country,
            ),
            discounts: $this->buildDiscounts($model->discounts),
            products: $this->buildProducts($model->products),
            customFields: $model->custom_fields,
        );
    }

    private static function buildShipping(OrderModel $model): ?OrderShipping
    {
        if ($model->shipping_method === null) {
            return null;
        }

        return new OrderShipping(
            name: $model->shipping_method,
            value: $model->shipping_cost ?? 0.0,
            vatRate: $model->shipping_vat_rate ?? 0.0,
        );
    }

    /**
     * @param Collection<int, OrderProductModel> $models
     *
     * @return array<int, OrderProduct>
     */
    private function buildProducts(Collection $models): array
    {
        return $models->map(static fn(OrderProductModel $m): OrderProduct => new OrderProduct(
            id: $m->external_id,
            title: $m->title,
            sku: $m->sku,
            price: $m->price,
            priceVat: $m->price_vat,
            total: $m->total,
            totalVat: $m->total_vat,
            originalPrice: $m->original_price,
            costPrice: $m->cost_price,
            quantity: $m->quantity,
            vatRate: $m->vat_rate,
            comments: $m->comments ?? '',
            variation: self::buildVariations($m->variation),
            customFields: self::buildCustomFields($m->custom_fields),
        ))->all();
    }

    /**
     * @param array<int, array{name: string, value: string}>|null $variations
     *
     * @return array<int, ProductVariation>
     */
    private static function buildVariations(?array $variations): array
    {
        if ($variations === null) {
            return [];
        }

        return \array_map(
            static fn(array $v): ProductVariation => ProductVariation::fromArray($v),
            $variations,
        );
    }

    /**
     * Convert DB custom fields format to Domain format.
     *
     * @param array<string, mixed>|null $customFields
     *
     * @return list<array{name: string, value: string}>
     */
    private static function buildCustomFields(?array $customFields): array
    {
        if ($customFields === null) {
            return [];
        }

        // DB stores as associative array, Domain expects indexed array of {name, value}
        $result = [];
        foreach ($customFields as $name => $value) {
            $result[] = [
                'name' => $name,
                'value' => \is_scalar($value) ? (string) $value : '',
            ];
        }

        return $result;
    }

    /**
     * @param Collection<int, OrderDiscountModel> $models
     *
     * @return array<int, OrderDiscount>
     */
    private function buildDiscounts(Collection $models): array
    {
        return $models->map(static fn(OrderDiscountModel $m): OrderDiscount => new OrderDiscount(
            name: $m->name,
            value: $m->value,
            type: $m->type,
            code: $m->code,
            voucherId: $m->voucher_id,
            offerId: $m->offer_id,
        ))->all();
    }
}
