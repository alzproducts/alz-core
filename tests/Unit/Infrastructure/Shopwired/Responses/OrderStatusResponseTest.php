<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\Shopwired\Responses\OrderStatusResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OrderStatusResponse Unit Tests.
 *
 * Tests the DTO-to-Domain transformation including:
 * - All known status type mappings
 * - Unknown status handling (API contract violation)
 * - Property preservation through transformation
 */
#[CoversClass(OrderStatusResponse::class)]
final class OrderStatusResponseTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | toDomain() Success Tests - All Known Status Types
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('knownStatusTypesProvider')]
    public function to_domain_converts_known_status_types(string $statusName, OrderStatusType $expectedType): void
    {
        $response = new OrderStatusResponse(
            id: 1,
            name: $statusName,
            type: 'custom',
            sortOrder: 0,
        );

        $domain = $response->toDomain();

        $this->assertInstanceOf(OrderStatus::class, $domain);
        $this->assertSame($expectedType, $domain->name);
        $this->assertSame('custom', $domain->type);
    }

    /**
     * @return array<string, array{string, OrderStatusType}>
     */
    public static function knownStatusTypesProvider(): array
    {
        return [
            'Not Paid' => ['Not Paid', OrderStatusType::NotPaid],
            'Part Paid' => ['Part Paid', OrderStatusType::PartPaid],
            'Paid' => ['Paid', OrderStatusType::Paid],
            'Cancelled' => ['Cancelled', OrderStatusType::Cancelled],
            'Dispatched' => ['Dispatched', OrderStatusType::Dispatched],
            'Completed' => ['Completed', OrderStatusType::Completed],
            'Part Refunded' => ['Part Refunded', OrderStatusType::PartRefunded],
            'Refunded' => ['Refunded', OrderStatusType::Refunded],
            'Awaiting Payment' => ['Awaiting Payment', OrderStatusType::AwaitingPayment],
            'Outstanding' => ['Outstanding', OrderStatusType::Outstanding],
            'Preorder' => ['Preorder', OrderStatusType::Preorder],
            'Overdue' => ['Overdue', OrderStatusType::Overdue],
            'Processing' => ['Processing', OrderStatusType::Processing],
            'Received' => ['Received', OrderStatusType::Received],
        ];
    }

    #[Test]
    public function to_domain_preserves_type_property(): void
    {
        $response = new OrderStatusResponse(
            id: 42,
            name: 'Paid',
            type: 'paid', // Note: type is separate from name enum
            sortOrder: 5,
        );

        $domain = $response->toDomain();

        $this->assertSame('paid', $domain->type);
    }

    /*
    |--------------------------------------------------------------------------
    | toDomain() Error Handling
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_domain_throws_on_unknown_status_name(): void
    {
        $response = new OrderStatusResponse(
            id: 1,
            name: 'Unknown New Status',
            type: 'custom',
            sortOrder: 0,
        );

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage("Unknown order status name 'Unknown New Status'. API may have added new status type.");

        $response->toDomain();
    }

    #[Test]
    public function to_domain_exception_contains_service_name(): void
    {
        $response = new OrderStatusResponse(
            id: 1,
            name: 'InvalidStatus',
            type: 'custom',
            sortOrder: 0,
        );

        try {
            $response->toDomain();
            $this->fail('Expected InvalidApiResponseException');
        } catch (InvalidApiResponseException $e) {
            $this->assertSame('ShopWired', $e->serviceName);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DTO Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function dto_stores_all_properties(): void
    {
        $response = new OrderStatusResponse(
            id: 123,
            name: 'Processing',
            type: 'custom',
            sortOrder: 10,
        );

        $this->assertSame(123, $response->id);
        $this->assertSame('Processing', $response->name);
        $this->assertSame('custom', $response->type);
        $this->assertSame(10, $response->sortOrder);
    }
}
