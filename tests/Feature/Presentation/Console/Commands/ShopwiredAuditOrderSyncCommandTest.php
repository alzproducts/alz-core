<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console\Commands;

use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Domain\Catalog\Order\Enums\PreOrderStatus;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderAddress;
use App\Domain\Catalog\Order\ValueObjects\OrderCustomer;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\ValueObjects\PaymentMethod;
use App\Presentation\Console\Commands\Shopwired\ShopwiredAuditOrderSyncCommand;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ShopwiredAuditOrderSyncCommand::class)]
final class ShopwiredAuditOrderSyncCommandTest extends TestCase
{
    #[Test]
    public function the_auditing_line_shows_british_dates(): void
    {
        $this->fakeOrders([]);

        $this->artisan('shopwired:audit-order-sync', ['--from' => '2026-01-05', '--to' => '2026-02-03'])
            ->expectsOutput('Auditing orders from 05/01/2026 to 03/02/2026...')
            ->assertSuccessful();
    }

    #[Test]
    public function missing_order_lines_show_british_order_dates(): void
    {
        $this->fakeOrders([self::order(new DateTimeImmutable('2026-01-07 09:15:00'))]);

        $this->artisan('shopwired:audit-order-sync', ['--show-missing' => true])
            ->expectsOutput('  - 5 | Ref: 10005 | 07/01/2026 | £100.00')
            ->assertFailed();
    }

    /**
     * @param list<Order> $apiOrders
     */
    private function fakeOrders(array $apiOrders): void
    {
        $this->mock(OrderClientInterface::class)->shouldReceive('listOrdersInRange')->once()->andReturn($apiOrders);
        $this->mock(OrderRepositoryInterface::class)->shouldReceive('getOrdersInDateRange')->once()->andReturn([]);
    }

    private static function order(DateTimeImmutable $placedAt): Order
    {
        $address = new OrderAddress(
            name: 'Test',
            emailAddress: 'test@example.com',
            telephone: '01234567890',
            companyName: '',
            addressLine1: '1 Test Street',
            addressLine2: '',
            addressLine3: '',
            state: null,
            city: 'London',
            province: '',
            postcode: 'SW1A 1AA',
            country: 'United Kingdom',
            countryId: 1,
        );

        return new Order(
            id: 5,
            reference: 10005,
            orderPlacedAt: $placedAt,
            total: 100.0,
            subTotalNet: 90.0,
            shippingTotalNet: 10.0,
            originalShippingTotalNet: 10.0,
            paymentMethod: PaymentMethod::Card,
            comments: '',
            marketing: false,
            hasVatRelief: false,
            isArchived: false,
            isAnonymized: false,
            lineItemVatCalculation: false,
            status: new OrderStatus(1, OrderStatusType::Completed, 'paid', 0),
            customer: new OrderCustomer(1, 1, null, []),
            shipping: null,
            billingAddress: $address,
            shippingAddress: $address,
            preOrderStatus: PreOrderStatus::None,
        );
    }
}
