<?php

declare(strict_types=1);

namespace App\Domain\ContactForm\Enums;

/**
 * Reason for contacting customer service.
 *
 * These values match the frontend form options and drive
 * conditional field visibility and HelpScout routing.
 */
enum ContactReason: string
{
    case ProductInformation = 'product_information';
    case CheckoutPayment = 'checkout_payment';
    case QuotationRequest = 'quotation_request';
    case MyOrderDelivery = 'my_order_delivery';
    case MyOrderReturns = 'my_order_returns';
    case MyOrderTechnicalSupport = 'my_order_technical_support';
    case MyOrderOtherQuery = 'my_order_other_query';
    case Marketing = 'marketing';
    case Other = 'other';

    /**
     * Human-readable label for display in HelpScout subject lines.
     */
    public function label(): string
    {
        return match ($this) {
            self::ProductInformation => 'Product Information',
            self::CheckoutPayment => 'Checkout/Payment',
            self::QuotationRequest => 'Quotation Request',
            self::MyOrderDelivery => 'My Order - Delivery',
            self::MyOrderReturns => 'My Order - Returns',
            self::MyOrderTechnicalSupport => 'My Order - Technical Support',
            self::MyOrderOtherQuery => 'My Order - Other Query',
            self::Marketing => 'Marketing',
            self::Other => 'Other',
        };
    }

    /**
     * Determines if this reason relates to an existing order.
     *
     * Order-related reasons require an order number and show
     * the "recently ordered products" field instead of "recently viewed".
     */
    public function isOrderRelated(): bool
    {
        return match ($this) {
            self::MyOrderDelivery,
            self::MyOrderReturns,
            self::MyOrderTechnicalSupport,
            self::MyOrderOtherQuery => true,
            self::ProductInformation,
            self::CheckoutPayment,
            self::QuotationRequest,
            self::Marketing,
            self::Other => false,
        };
    }

    /**
     * Get HelpScout tag for this reason.
     */
    public function helpScoutTag(): string
    {
        return match ($this) {
            self::ProductInformation => 'product-enquiry',
            self::CheckoutPayment => 'checkout-payment',
            self::QuotationRequest => 'quote-request',
            self::MyOrderDelivery => 'order-delivery',
            self::MyOrderReturns => 'order-returns',
            self::MyOrderTechnicalSupport => 'order-support',
            self::MyOrderOtherQuery => 'order-query',
            self::Marketing => 'marketing',
            self::Other => 'general-enquiry',
        };
    }
}
