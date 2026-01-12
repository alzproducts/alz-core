<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Mappers;

use App\Domain\Catalog\Order\Enums\PreOrderStatus;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderAddress;
use App\Domain\Catalog\Order\ValueObjects\OrderCustomer;
use App\Domain\Catalog\Order\ValueObjects\OrderDiscount;
use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use App\Domain\Catalog\Order\ValueObjects\OrderShipping;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\ValueObjects\PaymentMethod;
use App\Infrastructure\Concerns\MapperHelperTrait;
use App\Infrastructure\Shopwired\Models\OrderDiscountModel;
use App\Infrastructure\Shopwired\Models\OrderModel;
use App\Infrastructure\Shopwired\Models\OrderProductModel;

/**
 * Maps between OrderModel (Eloquent) and Order (Domain).
 *
 * Handles the complex transformations for Order entities including:
 * - Nested value objects (status, customer, addresses, shipping)
 * - Enum conversions with fallbacks
 * - Lifecycle status derivation
 *
 * Products and discounts are handled separately via their own model mappings.
 */
final class OrderModelMapper
{
    use MapperHelperTrait;

    /**
     * Convert Eloquent model with loaded relations to Domain Order.
     *
     * Preferred entry point - handles relation conversion internally.
     * Requires 'products' and 'discounts' relations to be eager-loaded.
     *
     * @param OrderModel $model The Eloquent model with loaded relations
     */
    public static function fromModelWithRelations(OrderModel $model): Order
    {
        $products = $model->products->map(
            static fn(OrderProductModel $m): OrderProduct => $m->toDomain(),
        )->all();

        /** @var list<OrderDiscount> $discounts */
        $discounts = $model->discounts->map(
            static fn(OrderDiscountModel $m): OrderDiscount => $m->toDomain(), // @phpstan-ignore return.type
        )->all();

        return self::toDomain($model, $products, $discounts);
    }

    /**
     * Convert Eloquent model to Domain Order with pre-converted relations.
     *
     * Use fromModelWithRelations() unless you need to provide custom relation data.
     *
     * @param OrderModel                $model     The Eloquent model to convert
     * @param array<int, OrderProduct>  $products  Already-converted product domain objects
     * @param array<int, OrderDiscount> $discounts Already-converted discount domain objects
     */
    public static function toDomain(
        OrderModel $model,
        array $products,
        array $discounts,
    ): Order {
        return new Order(
            id: $model->external_id,
            reference: $model->reference,
            orderPlacedAt: $model->order_placed_at->toDateTimeImmutable(),
            total: $model->total,
            subTotal: $model->sub_total,
            shippingTotal: $model->shipping_total,
            originalShippingTotal: $model->original_shipping_total,
            paymentMethod: self::buildEnum(
                PaymentMethod::class,
                $model->payment_method,
                PaymentMethod::Unknown,
                $model->external_id,
                'payment_method',
            ),
            comments: $model->comments ?? '',
            marketing: $model->marketing,
            hasVatRelief: $model->has_vat_relief,
            isArchived: $model->is_archived,
            isAnonymized: $model->is_anonymized,
            lineItemVatCalculation: $model->line_item_vat_calculation,
            status: self::buildStatus($model),
            customer: self::buildCustomer($model),
            shipping: self::buildShipping($model),
            billingAddress: self::buildBillingAddress($model),
            shippingAddress: self::buildShippingAddress($model),
            preOrderStatus: self::buildEnum(
                PreOrderStatus::class,
                $model->pre_order_status,
                PreOrderStatus::None,
                $model->external_id,
                'pre_order_status',
            ),
            taxValue: $model->tax_value,
            trackingUrl: $model->tracking_url,
            invoiceUrl: $model->invoice_url,
            transactionId: $model->transaction_id,
            deliveryDate: $model->delivery_date?->toDateTimeImmutable(),
            discounts: $discounts,
            products: $products,
            customFields: $model->custom_fields,
        );
    }

    /**
     * Convert Domain Order to Eloquent model attributes.
     *
     * Does not include primary key or relationship IDs (handled by repository).
     * Products and discounts are synced separately.
     *
     * @return array<string, mixed>
     */
    public static function toModelAttributes(Order $order): array
    {
        return [
            'reference' => $order->reference,
            'order_placed_at' => $order->orderPlacedAt,
            'total' => $order->total,
            'sub_total' => $order->subTotal,
            'shipping_total' => $order->shippingTotal,
            'original_shipping_total' => $order->originalShippingTotal,
            'tax_value' => $order->taxValue,
            'line_item_vat_calculation' => $order->lineItemVatCalculation,
            'status_id' => $order->status->id,
            'status_name' => $order->status->name->value,
            'status_type' => $order->status->type,
            'status_sort_order' => $order->status->sortOrder,
            'lifecycle_status' => StatusTypeToLifecycleMapper::toLifecycle($order->status->name)->value,
            'pre_order_status' => $order->preOrderStatus->value,
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
            'billing_country_id' => $order->billingAddress->countryId,
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
            'delivery_country_id' => $order->shippingAddress->countryId,
            'shipping_id' => $order->shipping?->id,
            'shipping_method' => $order->shipping?->name,
            'shipping_cost' => $order->shipping !== null ? $order->shipping->value : 0.0,
            'shipping_vat_rate' => $order->shipping?->vatRate,
            'tracking_url' => $order->trackingUrl,
            'invoice_url' => $order->invoiceUrl,
            'payment_method' => $order->paymentMethod->value,
            'transaction_id' => $order->transactionId,
            'delivery_date' => $order->deliveryDate,
            'marketing' => $order->marketing,
            'has_vat_relief' => $order->hasVatRelief,
            'is_archived' => $order->isArchived,
            'is_anonymized' => $order->isAnonymized,
            'comments' => $order->comments,
            'custom_fields' => $order->customFields,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private static function buildStatus(OrderModel $model): OrderStatus
    {
        /** @var OrderStatusType $statusType */
        $statusType = self::buildEnum(
            OrderStatusType::class,
            $model->status_name,
            OrderStatusType::Processing,
            $model->external_id,
            'status_name',
        );

        return new OrderStatus(
            id: $model->status_id,
            name: $statusType,
            type: $model->status_type,
            sortOrder: $model->status_sort_order ?? 0,
        );
    }

    private static function buildCustomer(OrderModel $model): OrderCustomer
    {
        return new OrderCustomer(
            id: $model->customer_id,
            type: $model->customer_type,
            dateOfBirth: $model->customer_date_of_birth,
            deviceInfo: $model->customer_device_info ?? [],
        );
    }

    private static function buildShipping(OrderModel $model): ?OrderShipping
    {
        if ($model->shipping_method === null) {
            return null;
        }

        return new OrderShipping(
            id: $model->shipping_id,
            name: $model->shipping_method,
            value: $model->shipping_cost ?? 0.0,
            vatRate: $model->shipping_vat_rate ?? 0.0,
        );
    }

    private static function buildBillingAddress(OrderModel $model): OrderAddress
    {
        return new OrderAddress(
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
            countryId: $model->billing_country_id,
        );
    }

    private static function buildShippingAddress(OrderModel $model): OrderAddress
    {
        return new OrderAddress(
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
            countryId: $model->delivery_country_id,
        );
    }
}
