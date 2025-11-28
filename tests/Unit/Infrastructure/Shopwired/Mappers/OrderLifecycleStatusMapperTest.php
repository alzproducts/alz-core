<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Mappers;

use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use App\Infrastructure\Shopwired\Mappers\OrderLifecycleStatusMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * OrderLifecycleStatusMapper Unit Tests.
 *
 * Tests the mapping from domain OrderLifecycleStatus enum to ShopWired status IDs.
 * These IDs are account-specific and verified against the production ShopWired account.
 */
#[CoversClass(OrderLifecycleStatusMapper::class)]
final class OrderLifecycleStatusMapperTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Status ID Mapping Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('statusMappingProvider')]
    public function it_maps_lifecycle_status_to_shopwired_id(
        OrderLifecycleStatus $status,
        int $expectedId,
    ): void {
        $actualId = OrderLifecycleStatusMapper::toShopwiredId($status);

        self::assertSame($expectedId, $actualId);
    }

    /**
     * @return array<string, array{OrderLifecycleStatus, int}>
     */
    public static function statusMappingProvider(): array
    {
        return [
            'Processing maps to 178012' => [OrderLifecycleStatus::Processing, 178012],
            'Dispatched maps to 73879' => [OrderLifecycleStatus::Dispatched, 73879],
            'PartDispatched maps to 73879 (same as Dispatched)' => [OrderLifecycleStatus::PartDispatched, 73879],
            'PartRefunded maps to 73881' => [OrderLifecycleStatus::PartRefunded, 73881],
            'Refunded maps to 73882' => [OrderLifecycleStatus::Refunded, 73882],
            'Cancelled maps to 73878' => [OrderLifecycleStatus::Cancelled, 73878],
        ];
    }

    #[Test]
    public function it_maps_all_enum_cases(): void
    {
        // Ensure every OrderLifecycleStatus case has a mapping
        foreach (OrderLifecycleStatus::cases() as $status) {
            $id = OrderLifecycleStatusMapper::toShopwiredId($status);

            self::assertIsInt($id);
            self::assertGreaterThan(0, $id);
        }
    }

    #[Test]
    public function part_dispatched_uses_dispatched_id(): void
    {
        // Business rule: ShopWired has no part-dispatched status, so we use Dispatched
        $dispatchedId = OrderLifecycleStatusMapper::toShopwiredId(OrderLifecycleStatus::Dispatched);
        $partDispatchedId = OrderLifecycleStatusMapper::toShopwiredId(OrderLifecycleStatus::PartDispatched);

        self::assertSame($dispatchedId, $partDispatchedId);
    }
}
