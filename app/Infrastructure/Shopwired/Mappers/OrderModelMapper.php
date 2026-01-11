<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Mappers;

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
use App\Infrastructure\Shopwired\Models\OrderModel;
use DateTimeImmutable;

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
     * Convert Eloquent model to Domain Order.
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
            total: $model->total,
            subTotal: $model->sub_total,
            shippingTotal: $model->shipping_total,
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
            status: self::buildStatus($model),
            customer: self::buildCustomer($model),
            shipping: self::buildShipping($model),
            billingAddress: self::buildBillingAddress($model),
            shippingAddress: self::buildShippingAddress($model),
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
            'total' => $order->total,
            'sub_total' => $order->subTotal,
            'shipping_total' => $order->shippingTotal,
            'status_id' => null, // Not available from Domain - may be populated by direct API sync
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
            name: $statusType,
            type: $model->status_type,
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
        );
    }
}
