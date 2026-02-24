<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\ValueObjects\OrderCustomer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OrderCustomer Value Object Unit Tests.
 *
 * Tests the OrderCustomer domain value object including assertions
 * and device info accessor methods.
 */
#[CoversClass(OrderCustomer::class)]
final class OrderCustomerTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a valid order customer with optional overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private function createOrderCustomer(array $overrides = []): OrderCustomer
    {
        $defaults = [
            'id' => 42,
            'type' => 1,
            'dateOfBirth' => '1985-06-15',
            'deviceInfo' => [],
        ];

        $data = \array_merge($defaults, $overrides);

        return new OrderCustomer(...$data);
    }

    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_order_customer_with_valid_data(): void
    {
        $customer = $this->createOrderCustomer();

        $this->assertSame(42, $customer->id);
        $this->assertSame(1, $customer->type);
        $this->assertSame('1985-06-15', $customer->dateOfBirth);
        $this->assertSame([], $customer->deviceInfo);
    }

    #[Test]
    public function it_creates_order_customer_with_null_date_of_birth(): void
    {
        $customer = $this->createOrderCustomer(['dateOfBirth' => null]);

        $this->assertNull($customer->dateOfBirth);
    }

    /*
    |--------------------------------------------------------------------------
    | ID Assertion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('invalidIdProvider')]
    public function it_throws_if_id_is_negative(int $invalidId): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Customer ID must be non-negative');

        $this->createOrderCustomer(['id' => $invalidId]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidIdProvider(): array
    {
        return [
            'negative id' => [-1],
            'large negative id' => [-99999],
        ];
    }

    #[Test]
    public function it_accepts_positive_boundary_id(): void
    {
        $customer = $this->createOrderCustomer(['id' => 1]);

        $this->assertSame(1, $customer->id);
    }

    #[Test]
    public function it_accepts_zero_id_for_legacy_guest_orders(): void
    {
        $customer = $this->createOrderCustomer(['id' => 0]);

        $this->assertSame(0, $customer->id);
    }

    /*
    |--------------------------------------------------------------------------
    | ipAddress() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function ip_address_returns_null_when_device_info_empty(): void
    {
        $customer = $this->createOrderCustomer(['deviceInfo' => []]);

        $this->assertNull($customer->ipAddress());
    }

    #[Test]
    public function ip_address_returns_null_when_key_missing(): void
    {
        $customer = $this->createOrderCustomer([
            'deviceInfo' => ['userAgent' => 'Mozilla/5.0'],
        ]);

        $this->assertNull($customer->ipAddress());
    }

    #[Test]
    public function ip_address_returns_null_when_value_not_string(): void
    {
        $customer = $this->createOrderCustomer([
            'deviceInfo' => ['ipAddress' => 12345],
        ]);

        $this->assertNull($customer->ipAddress());
    }

    #[Test]
    public function ip_address_returns_value_when_present_and_string(): void
    {
        $customer = $this->createOrderCustomer([
            'deviceInfo' => ['ipAddress' => '192.168.1.100'],
        ]);

        $this->assertSame('192.168.1.100', $customer->ipAddress());
    }

    /*
    |--------------------------------------------------------------------------
    | userAgent() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function user_agent_returns_null_when_device_info_empty(): void
    {
        $customer = $this->createOrderCustomer(['deviceInfo' => []]);

        $this->assertNull($customer->userAgent());
    }

    #[Test]
    public function user_agent_returns_null_when_key_missing(): void
    {
        $customer = $this->createOrderCustomer([
            'deviceInfo' => ['ipAddress' => '127.0.0.1'],
        ]);

        $this->assertNull($customer->userAgent());
    }

    #[Test]
    public function user_agent_returns_null_when_value_not_string(): void
    {
        $customer = $this->createOrderCustomer([
            'deviceInfo' => ['userAgent' => ['nested' => 'array']],
        ]);

        $this->assertNull($customer->userAgent());
    }

    #[Test]
    public function user_agent_returns_value_when_present_and_string(): void
    {
        $customer = $this->createOrderCustomer([
            'deviceInfo' => ['userAgent' => 'Mozilla/5.0 (Windows NT 10.0)'],
        ]);

        $this->assertSame('Mozilla/5.0 (Windows NT 10.0)', $customer->userAgent());
    }

    /*
    |--------------------------------------------------------------------------
    | awinChannel() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function awin_channel_returns_null_when_device_info_empty(): void
    {
        $customer = $this->createOrderCustomer(['deviceInfo' => []]);

        $this->assertNull($customer->awinChannel());
    }

    #[Test]
    public function awin_channel_returns_null_when_key_missing(): void
    {
        $customer = $this->createOrderCustomer([
            'deviceInfo' => ['ipAddress' => '127.0.0.1'],
        ]);

        $this->assertNull($customer->awinChannel());
    }

    #[Test]
    public function awin_channel_returns_null_when_value_not_string(): void
    {
        $customer = $this->createOrderCustomer([
            'deviceInfo' => ['awinChannel' => 123],
        ]);

        $this->assertNull($customer->awinChannel());
    }

    #[Test]
    public function awin_channel_returns_value_when_present_and_string(): void
    {
        $customer = $this->createOrderCustomer([
            'deviceInfo' => ['awinChannel' => 'aw_affiliate_123'],
        ]);

        $this->assertSame('aw_affiliate_123', $customer->awinChannel());
    }

    /*
    |--------------------------------------------------------------------------
    | Full Device Info Test
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_handles_complete_device_info(): void
    {
        $customer = $this->createOrderCustomer([
            'deviceInfo' => [
                'ipAddress' => '10.0.0.1',
                'userAgent' => 'Safari/537.36',
                'awinChannel' => 'partner_xyz',
                'facebookBrowserId' => 'fb_123',
                'gclid' => 'gclid_abc',
            ],
        ]);

        $this->assertSame('10.0.0.1', $customer->ipAddress());
        $this->assertSame('Safari/537.36', $customer->userAgent());
        $this->assertSame('partner_xyz', $customer->awinChannel());
    }
}
