<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Mixpanel\UseCases;

use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Contracts\Shopwired\CustomerRepositoryInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Mixpanel\UseCases\SyncOrdersToMixpanelUseCase;
use App\Domain\Catalog\Order\Enums\PreOrderStatus;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderAddress;
use App\Domain\Catalog\Order\ValueObjects\OrderAnalyticsHash;
use App\Domain\Catalog\Order\ValueObjects\OrderCustomer;
use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\ValueObjects\PaymentMethod;
use App\Domain\Exceptions\MissingRequiredDataException;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Minimal tests for UseCase branching logic.
 *
 * Per TestingStrategy.md: UseCases get minimal tests for branches,
 * not comprehensive mocking. No mutation testing required.
 */
#[CoversClass(SyncOrdersToMixpanelUseCase::class)]
final class SyncOrdersToMixpanelUseCaseTest extends TestCase
{
    private OrderRepositoryInterface&MockInterface $orderRepository;

    private CustomerRepositoryInterface&MockInterface $customerRepository;

    private MixpanelClientInterface&MockInterface $mixpanel;

    private LoggerInterface&MockInterface $logger;

    private SyncOrdersToMixpanelUseCase $useCase;

    private string $salt = 'test-salt';

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->customerRepository = Mockery::mock(CustomerRepositoryInterface::class);
        $this->mixpanel = Mockery::mock(MixpanelClientInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new SyncOrdersToMixpanelUseCase(
            $this->orderRepository,
            $this->customerRepository,
            $this->mixpanel,
            $this->salt,
            $this->logger,
        );
    }

    #[Test]
    public function returns_empty_result_when_no_orders_in_range(): void
    {
        $from = new DateTimeImmutable('-30 days');
        $to = new DateTimeImmutable('-7 days');

        $this->mixpanel->shouldReceive('getExistingOrderHashes')->andReturn([]);
        $this->orderRepository->shouldReceive('getOrdersInDateRange')->andReturn([]);

        $result = $this->useCase->execute($from, $to);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->ordersInRange);
    }

    #[Test]
    public function skips_orders_already_in_mixpanel(): void
    {
        $from = new DateTimeImmutable('-30 days');
        $to = new DateTimeImmutable('-7 days');

        $order = $this->createOrder(1, 10001);
        $hash = OrderAnalyticsHash::fromReference($order->reference, $this->salt);

        $this->mixpanel->shouldReceive('getExistingOrderHashes')->andReturn([$hash->value]);
        $this->orderRepository->shouldReceive('getOrdersInDateRange')->andReturn([$order]);

        $result = $this->useCase->execute($from, $to);

        $this->assertSame(1, $result->ordersInRange);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $result->synced);
    }

    #[Test]
    public function syncs_new_orders_and_skips_existing(): void
    {
        $from = new DateTimeImmutable('-30 days');
        $to = new DateTimeImmutable('-7 days');

        $existingOrder = $this->createOrder(1, 10001);
        $newOrder = $this->createOrderWithProducts(2, 10002, productCount: 2);

        $existingHash = OrderAnalyticsHash::fromReference($existingOrder->reference, $this->salt);

        $this->mixpanel->shouldReceive('getExistingOrderHashes')->andReturn([$existingHash->value]);
        $this->orderRepository->shouldReceive('getOrdersInDateRange')->andReturn([$existingOrder, $newOrder]);
        $this->customerRepository->shouldReceive('getTradeStatusByIds')
            ->with([2])
            ->andReturn([2 => false]);
        $this->mixpanel->shouldReceive('importOrders')->once();

        $result = $this->useCase->execute($from, $to);

        $this->assertSame(2, $result->ordersInRange);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(1, $result->synced);
        $this->assertSame(2, $result->productEventsCreated);
    }

    #[Test]
    public function throws_when_customer_missing_from_database(): void
    {
        $from = new DateTimeImmutable('-30 days');
        $to = new DateTimeImmutable('-7 days');

        $order = $this->createOrder(customerId: 999, reference: 10001);

        $this->mixpanel->shouldReceive('getExistingOrderHashes')->andReturn([]);
        $this->orderRepository->shouldReceive('getOrdersInDateRange')->andReturn([$order]);
        $this->customerRepository->shouldReceive('getTradeStatusByIds')
            ->with([999])
            ->andReturn([]); // Customer not found

        $this->expectException(MissingRequiredDataException::class);
        $this->expectExceptionMessage('999');

        $this->useCase->execute($from, $to);
    }

    private function createOrder(int $customerId, int $reference): Order
    {
        return new Order(
            id: $reference,
            reference: $reference,
            orderPlacedAt: new DateTimeImmutable('-10 days'),
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
            customer: new OrderCustomer($customerId, 0, null, []),
            shipping: null,
            billingAddress: $this->createAddress(),
            shippingAddress: $this->createAddress(),
            preOrderStatus: PreOrderStatus::None,
        );
    }

    private function createOrderWithProducts(int $customerId, int $reference, int $productCount): Order
    {
        $products = [];
        for ($i = 1; $i <= $productCount; $i++) {
            $products[] = new OrderProduct(
                id: $i,
                orderExternalId: $reference,
                title: "Product {$i}",
                sku: "SKU-{$i}",
                price: 45.0,
                priceVat: 9.0,
                total: 45.0,
                totalVat: 9.0,
                originalPrice: 45.0,
                costPrice: null,
                quantity: 1,
                vatRate: 20.0,
                comments: '',
                isPreorder: false,
            );
        }

        return new Order(
            id: $reference,
            reference: $reference,
            orderPlacedAt: new DateTimeImmutable('-10 days'),
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
            customer: new OrderCustomer($customerId, 0, null, []),
            shipping: null,
            billingAddress: $this->createAddress(),
            shippingAddress: $this->createAddress(),
            preOrderStatus: PreOrderStatus::None,
            products: $products,
        );
    }

    private function createAddress(): OrderAddress
    {
        return new OrderAddress(
            name: 'Test',
            emailAddress: 'test@example.com',
            telephone: '01onal234567890',
            companyName: '',
            addressLine1: '123 Test St',
            addressLine2: '',
            addressLine3: null,
            city: 'London',
            province: '',
            state: null,
            postcode: 'SW1A 1AA',
            country: 'UK',
            countryId: 1,
        );
    }
}
