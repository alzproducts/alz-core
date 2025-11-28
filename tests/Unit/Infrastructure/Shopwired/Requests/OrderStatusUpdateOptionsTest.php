<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Requests;

use App\Infrastructure\Shopwired\Requests\OrderStatusUpdateOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * OrderStatusUpdateOptions Unit Tests.
 *
 * Tests the immutable options object for ShopWired order status update API.
 * Covers construction defaults, explicit value handling, and toArray() null filtering.
 */
#[CoversClass(OrderStatusUpdateOptions::class)]
final class OrderStatusUpdateOptionsTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_options_with_all_nulls_by_default(): void
    {
        $options = new OrderStatusUpdateOptions();

        self::assertNull($options->sendEmail);
        self::assertNull($options->trackingUrl);
        self::assertNull($options->sendToEbay);
        self::assertNull($options->eBayShippingCarrier);
        self::assertNull($options->eBayShipmentTrackingNumber);
    }

    #[Test]
    public function it_creates_options_with_all_values_set(): void
    {
        $options = new OrderStatusUpdateOptions(
            sendEmail: true,
            trackingUrl: 'https://track.example.com/ABC123',
            sendToEbay: false,
            eBayShippingCarrier: 'Royal Mail',
            eBayShipmentTrackingNumber: 'RM123456789GB',
        );

        self::assertTrue($options->sendEmail);
        self::assertSame('https://track.example.com/ABC123', $options->trackingUrl);
        self::assertFalse($options->sendToEbay);
        self::assertSame('Royal Mail', $options->eBayShippingCarrier);
        self::assertSame('RM123456789GB', $options->eBayShipmentTrackingNumber);
    }

    #[Test]
    public function it_accepts_false_boolean_values(): void
    {
        $options = new OrderStatusUpdateOptions(
            sendEmail: false,
            sendToEbay: false,
        );

        self::assertFalse($options->sendEmail);
        self::assertFalse($options->sendToEbay);
    }

    /*
    |--------------------------------------------------------------------------
    | toArray Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_array_returns_empty_when_all_null(): void
    {
        $options = new OrderStatusUpdateOptions();

        self::assertSame([], $options->toArray());
    }

    #[Test]
    public function to_array_includes_only_non_null_values(): void
    {
        $options = new OrderStatusUpdateOptions(
            sendEmail: true,
            trackingUrl: 'https://track.example.com/XYZ',
        );

        $array = $options->toArray();

        self::assertCount(2, $array);
        self::assertArrayHasKey('sendEmail', $array);
        self::assertArrayHasKey('trackingUrl', $array);
        self::assertArrayNotHasKey('sendToEbay', $array);
        self::assertArrayNotHasKey('eBayShippingCarrier', $array);
        self::assertArrayNotHasKey('eBayShipmentTrackingNumber', $array);
    }

    #[Test]
    public function to_array_includes_false_boolean_values(): void
    {
        $options = new OrderStatusUpdateOptions(
            sendEmail: false,
            sendToEbay: false,
        );

        $array = $options->toArray();

        // false is not null, so it should be included
        self::assertArrayHasKey('sendEmail', $array);
        self::assertFalse($array['sendEmail']);
        self::assertArrayHasKey('sendToEbay', $array);
        self::assertFalse($array['sendToEbay']);
    }

    #[Test]
    public function to_array_includes_all_fields_when_fully_configured(): void
    {
        $options = new OrderStatusUpdateOptions(
            sendEmail: true,
            trackingUrl: 'https://track.example.com/FULL',
            sendToEbay: true,
            eBayShippingCarrier: 'DPD',
            eBayShipmentTrackingNumber: 'DPD987654321',
        );

        $array = $options->toArray();

        self::assertCount(5, $array);
        self::assertTrue($array['sendEmail']);
        self::assertSame('https://track.example.com/FULL', $array['trackingUrl']);
        self::assertTrue($array['sendToEbay']);
        self::assertSame('DPD', $array['eBayShippingCarrier']);
        self::assertSame('DPD987654321', $array['eBayShipmentTrackingNumber']);
    }

    #[Test]
    public function to_array_preserves_exact_key_names_for_api(): void
    {
        $options = new OrderStatusUpdateOptions(
            sendEmail: true,
            sendToEbay: true,
            eBayShippingCarrier: 'Test',
            eBayShipmentTrackingNumber: '123',
        );

        $array = $options->toArray();

        // Verify camelCase keys match API expectations exactly
        self::assertArrayHasKey('sendEmail', $array);
        self::assertArrayHasKey('sendToEbay', $array);
        self::assertArrayHasKey('eBayShippingCarrier', $array);
        self::assertArrayHasKey('eBayShipmentTrackingNumber', $array);
    }
}
