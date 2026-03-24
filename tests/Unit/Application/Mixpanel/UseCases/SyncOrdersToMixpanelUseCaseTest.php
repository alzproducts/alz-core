<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Mixpanel\UseCases;

use App\Application\Contracts\ErrorReporterInterface;
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
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
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

    private ErrorReporterInterface&MockInterface $errorReporter;

    private LoggerInterface&MockInterface $logger;

    private SyncOrdersToMixpanelUseCase $useCase;

    private string $salt = 'test-salt';

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->customerRepository = Mockery::mock(CustomerRepositoryInterface::class);
        $this->mixpanel = Mockery::mock(MixpanelClientInterface::class);
        $this->errorReporter = Mockery::mock(ErrorReporterInterface::class);
        $this->errorReporter->shouldReceive('report')->byDefault();
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();
        $this->logger->shouldReceive('debug')->byDefault();
        $this->logger->shouldReceive('warning')->byDefault();

        $this->useCase = new SyncOrdersToMixpanelUseCase(
            $this->orderRepository,
            $this->customerRepository,
            $this->mixpanel,
            $this->errorReporter,
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

    /*
    |--------------------------------------------------------------------------
    | Empty-SKU Filtering Tests (Issue #353)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function skips_order_with_empty_sku_product_and_reports_to_sentry(): void
    {
        $from = new DateTimeImmutable('-30 days');
        $to = new DateTimeImmutable('-7 days');

        $orderWithEmptySku = $this->createOrderWithEmptySkuProduct(1, 10001);

        $this->mixpanel->shouldReceive('getExistingOrderHashes')->andReturn([]);
        $this->orderRepository->shouldReceive('getOrdersInDateRange')->andReturn([$orderWithEmptySku]);

        $this->errorReporter->shouldReceive('report')
            ->once()
            ->withArgs(static fn(MissingRequiredDataException $exception, array $context): bool => $exception->dataType === 'product SKU'
                    && $exception->operation === 'Mixpanel order sync'
                    && \str_contains((string) $exception->resolution, '#10001')
                    && $context['order_reference'] === 10001);

        $result = $this->useCase->execute($from, $to);

        $this->assertSame(1, $result->ordersInRange);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $result->synced);
    }

    #[Test]
    public function syncs_valid_orders_and_skips_empty_sku_orders(): void
    {
        $from = new DateTimeImmutable('-30 days');
        $to = new DateTimeImmutable('-7 days');

        $validOrder = $this->createOrderWithProducts(1, 10001, productCount: 2);
        $emptySkuOrder = $this->createOrderWithEmptySkuProduct(2, 10002);

        $this->mixpanel->shouldReceive('getExistingOrderHashes')->andReturn([]);
        $this->orderRepository->shouldReceive('getOrdersInDateRange')->andReturn([$validOrder, $emptySkuOrder]);
        $this->customerRepository->shouldReceive('getTradeStatusByIds')
            ->with([1])
            ->andReturn([1 => false]);
        $this->mixpanel->shouldReceive('importOrders')->once();
        $this->errorReporter->shouldReceive('report')->once();

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

        $order = $this->createOrderWithProducts(customerId: 999, reference: 10001, productCount: 1);

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

    private function createOrderWithEmptySkuProduct(int $customerId, int $reference): Order
    {
        $products = [
            new OrderProduct(
                id: 1,
                orderExternalId: $reference,
                title: 'Valid Product',
                sku: 'SKU-1',
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
            ),
            new OrderProduct(
                id: 2,
                orderExternalId: $reference,
                title: 'Missing SKU Product',
                sku: '',
                price: 30.0,
                priceVat: 6.0,
                total: 30.0,
                totalVat: 6.0,
                originalPrice: 30.0,
                costPrice: null,
                quantity: 1,
                vatRate: 20.0,
                comments: '',
                isPreorder: false,
            ),
        ];

        return new Order(
            id: $reference,
            reference: $reference,
            orderPlacedAt: new DateTimeImmutable('-10 days'),
            total: 75.0,
            subTotalNet: 65.0,
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
            telephone: '01234567890',
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

    private function createOrderWithDate(int $customerId, int $reference, DateTimeImmutable $orderPlacedAt): Order
    {
        return new Order(
            id: $reference,
            reference: $reference,
            orderPlacedAt: $orderPlacedAt,
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

    /*
    |--------------------------------------------------------------------------
    | Multi-Hash Matching Tests (Issue #134 - Fallback Salt Bug)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function skips_orders_with_fallback_salt_hash(): void
    {
        $from = new DateTimeImmutable('-30 days');
        $to = new DateTimeImmutable('-7 days');

        // Order placed at specific time (used for fallback salt calculation)
        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');
        $order = $this->createOrderWithDate(1, 10001, $orderPlacedAt);

        // Generate hash using FALLBACK salt (frontend bug scenario)
        $fallbackSalt = 'alz-' . $orderPlacedAt->getTimestamp();
        $fallbackHash = \hash('sha256', $order->reference . $fallbackSalt);

        $this->mixpanel->shouldReceive('getExistingOrderHashes')->andReturn([$fallbackHash]);
        $this->orderRepository->shouldReceive('getOrdersInDateRange')->andReturn([$order]);

        $result = $this->useCase->execute($from, $to);

        // Order should be skipped (detected via fallback salt hash)
        $this->assertSame(1, $result->ordersInRange);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $result->synced);
    }

    #[Test]
    public function skips_orders_with_legacy_base64_hash(): void
    {
        $from = new DateTimeImmutable('-30 days');
        $to = new DateTimeImmutable('-7 days');

        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');
        $order = $this->createOrderWithDate(1, 10001, $orderPlacedAt);

        // Generate hash using LEGACY BASE64 algorithm (old browser scenario)
        $input = $order->reference . $this->salt;
        $base64 = \base64_encode($input);
        $stripped = \preg_replace('/[^a-zA-Z0-9]/', '', $base64);
        $legacyHash = \mb_substr($stripped, 0, 32);

        $this->mixpanel->shouldReceive('getExistingOrderHashes')->andReturn([$legacyHash]);
        $this->orderRepository->shouldReceive('getOrdersInDateRange')->andReturn([$order]);

        $result = $this->useCase->execute($from, $to);

        // Order should be skipped (detected via legacy base64 hash)
        $this->assertSame(1, $result->ordersInRange);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $result->synced);
    }

    #[Test]
    public function skips_orders_with_legacy_base64_fallback_salt_hash(): void
    {
        $from = new DateTimeImmutable('-30 days');
        $to = new DateTimeImmutable('-7 days');

        $orderPlacedAt = new DateTimeImmutable('2026-01-21 09:00:00 UTC');
        $order = $this->createOrderWithDate(1, 10001, $orderPlacedAt);

        // Generate hash using LEGACY BASE64 + FALLBACK SALT (old browser + frontend bug)
        $fallbackSalt = 'alz-' . $orderPlacedAt->getTimestamp();
        $input = $order->reference . $fallbackSalt;
        $base64 = \base64_encode($input);
        $stripped = \preg_replace('/[^a-zA-Z0-9]/', '', $base64);
        $legacyFallbackHash = \mb_substr($stripped, 0, 32);

        $this->mixpanel->shouldReceive('getExistingOrderHashes')->andReturn([$legacyFallbackHash]);
        $this->orderRepository->shouldReceive('getOrdersInDateRange')->andReturn([$order]);

        $result = $this->useCase->execute($from, $to);

        // Order should be skipped (detected via legacy + fallback hash)
        $this->assertSame(1, $result->ordersInRange);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $result->synced);
    }

    #[Test]
    public function syncs_orders_not_matching_any_hash_variation(): void
    {
        $from = new DateTimeImmutable('-30 days');
        $to = new DateTimeImmutable('-7 days');

        $order = $this->createOrderWithProducts(1, 10001, 1);

        // Completely unrelated hash (order not in Mixpanel at all)
        $unrelatedHash = 'completely_different_hash_that_will_not_match';

        $this->mixpanel->shouldReceive('getExistingOrderHashes')->andReturn([$unrelatedHash]);
        $this->orderRepository->shouldReceive('getOrdersInDateRange')->andReturn([$order]);
        $this->customerRepository->shouldReceive('getTradeStatusByIds')
            ->with([1])
            ->andReturn([1 => false]);
        $this->mixpanel->shouldReceive('importOrders')->once();

        $result = $this->useCase->execute($from, $to);

        // Order should be synced (not found under any hash variation)
        $this->assertSame(1, $result->ordersInRange);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(1, $result->synced);
    }

    /*
    |--------------------------------------------------------------------------
    | Export API Chunking Tests (Issue #231)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function short_range_makes_single_export_call(): void
    {
        // 3-day range + 24h lookback = 4 days → single chunk (< 7 days)
        $from = new DateTimeImmutable('2025-12-01 00:00:00');
        $to = new DateTimeImmutable('2025-12-04 00:00:00');

        $this->mixpanel->shouldReceive('getExistingOrderHashes')
            ->once()
            ->andReturn([]);
        $this->orderRepository->shouldReceive('getOrdersInDateRange')->andReturn([]);

        $this->useCase->execute($from, $to);
    }

    #[Test]
    public function long_range_makes_multiple_export_calls(): void
    {
        // 25 days + 24h lookback = 26 days → 4 chunks (7+7+7+5)
        $from = new DateTimeImmutable('2025-11-01 00:00:00');
        $to = new DateTimeImmutable('2025-11-26 00:00:00');

        $this->mixpanel->shouldReceive('getExistingOrderHashes')
            ->times(4)
            ->andReturn(['hash_a'], ['hash_b'], ['hash_c'], ['hash_d']);
        $this->orderRepository->shouldReceive('getOrdersInDateRange')->andReturn([]);

        $this->useCase->execute($from, $to);
    }

    #[Test]
    public function hashes_from_multiple_chunks_are_merged_and_deduplicated(): void
    {
        // 14 days + 24h lookback = Oct 17 → Oct 31 (15 inclusive days) → 3 chunks (7+7+1)
        $from = new DateTimeImmutable('2025-10-18 00:00:00');
        $to = new DateTimeImmutable('2025-10-31 00:00:00');

        $order = $this->createOrder(1, 10001);
        $hash = OrderAnalyticsHash::fromReference($order->reference, $this->salt);

        // Chunks return overlapping hashes — "duplicate_hash" appears twice
        $this->mixpanel->shouldReceive('getExistingOrderHashes')
            ->times(3)
            ->andReturn(
                [$hash->value, 'duplicate_hash'],
                ['duplicate_hash', 'unique_hash_b'],
                ['unique_hash_c'],
            );

        $this->orderRepository->shouldReceive('getOrdersInDateRange')->andReturn([$order]);

        $result = $this->useCase->execute($from, $to);

        // Order's hash was found → should be skipped
        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $result->synced);
    }

    #[Test]
    public function export_chunk_failure_aborts_entire_sync(): void
    {
        // Same range as above — 3 chunks, second chunk fails
        $from = new DateTimeImmutable('2025-10-18 00:00:00');
        $to = new DateTimeImmutable('2025-10-31 00:00:00');

        // First chunk succeeds, second chunk throws
        $this->mixpanel->shouldReceive('getExistingOrderHashes')
            ->once()
            ->andReturn(['hash_from_chunk_1']);
        $this->mixpanel->shouldReceive('getExistingOrderHashes')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Mixpanel'));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->useCase->execute($from, $to);
    }
}
