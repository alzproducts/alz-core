<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Requests;

/**
 * Options for updating an order's status via ShopWired API.
 *
 * All fields are optional and nullable:
 * - `null` = use API's default behavior (not sent in payload)
 * - explicit value = override API default
 *
 * @see https://shopwired.readme.io/reference/post_orders-id-status
 */
final readonly class OrderStatusUpdateOptions
{
    /**
     * @param bool|null $sendEmail Whether to send order status update email to customer
     * @param string|null $trackingUrl New value for the tracking URL field
     * @param bool|null $sendToEbay Whether to send updated order status to eBay
     * @param string|null $eBayShippingCarrier Value for the eBay shipping carrier
     * @param string|null $eBayShipmentTrackingNumber Value for the eBay shipping tracking number
     */
    public function __construct(
        public ?bool $sendEmail = null,
        public ?string $trackingUrl = null,
        public ?bool $sendToEbay = null,
        public ?string $eBayShippingCarrier = null,
        public ?string $eBayShipmentTrackingNumber = null,
    ) {}

    /**
     * Convert to array for API request body.
     *
     * Only includes non-null values to respect API defaults.
     *
     * @return array<string, bool|string>
     */
    public function toArray(): array
    {
        return \array_filter([
            'sendEmail' => $this->sendEmail,
            'trackingUrl' => $this->trackingUrl,
            'sendToEbay' => $this->sendToEbay,
            'eBayShippingCarrier' => $this->eBayShippingCarrier,
            'eBayShipmentTrackingNumber' => $this->eBayShipmentTrackingNumber,
        ], static fn(mixed $v): bool => $v !== null);
    }
}
