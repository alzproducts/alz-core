<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Requests;

use App\Domain\Linnworks\Enums\PurchaseOrderStatus;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Requests\ChangePurchaseOrderStatusRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ChangePurchaseOrderStatusRequest::class)]
final class ChangePurchaseOrderStatusRequestTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | fromResolved — field mapping
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_maps_guid_and_status_to_array_output(): void
    {
        $purchaseId = new Guid('550e8400-e29b-41d4-a716-446655440000');

        $result = ChangePurchaseOrderStatusRequest::fromResolved($purchaseId, PurchaseOrderStatus::Open)->toArray();

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $result['pkPurchaseId']);
        $this->assertSame('OPEN', $result['status']);
    }

    /*
    |--------------------------------------------------------------------------
    | fromResolved — multiple status values
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_maps_pending_status(): void
    {
        $result = ChangePurchaseOrderStatusRequest::fromResolved(
            new Guid('550e8400-e29b-41d4-a716-446655440000'),
            PurchaseOrderStatus::Pending,
        )->toArray();

        $this->assertSame('PENDING', $result['status']);
    }

    #[Test]
    public function it_maps_partial_status(): void
    {
        $result = ChangePurchaseOrderStatusRequest::fromResolved(
            new Guid('550e8400-e29b-41d4-a716-446655440000'),
            PurchaseOrderStatus::Partial,
        )->toArray();

        $this->assertSame('PARTIAL', $result['status']);
    }

    #[Test]
    public function it_maps_delivered_status(): void
    {
        $result = ChangePurchaseOrderStatusRequest::fromResolved(
            new Guid('550e8400-e29b-41d4-a716-446655440000'),
            PurchaseOrderStatus::Delivered,
        )->toArray();

        $this->assertSame('DELIVERED', $result['status']);
    }

    /*
    |--------------------------------------------------------------------------
    | toArray — API key names
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_correct_api_keys(): void
    {
        $result = ChangePurchaseOrderStatusRequest::fromResolved(
            new Guid('550e8400-e29b-41d4-a716-446655440000'),
            PurchaseOrderStatus::Open,
        )->toArray();

        $this->assertArrayHasKey('pkPurchaseId', $result);
        $this->assertArrayHasKey('status', $result);
    }
}
