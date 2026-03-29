<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Linnworks\UseCases\PurchaseOrder;

use App\Application\Contracts\Linnworks\PurchaseOrderUpdateClientInterface;
use App\Application\Linnworks\UseCases\PurchaseOrder\ChangePurchaseOrderStatusUseCase;
use App\Domain\Linnworks\Enums\PurchaseOrderStatus;
use App\Domain\Linnworks\Exceptions\InvalidPurchaseOrderStatusTransitionException;
use App\Domain\ValueObjects\Guid;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * ChangePurchaseOrderStatusUseCase Unit Tests.
 *
 * Tests domain rule enforcement before API delegation:
 * valid transitions are forwarded; invalid transitions throw without calling the API.
 */
#[CoversClass(ChangePurchaseOrderStatusUseCase::class)]
final class ChangePurchaseOrderStatusUseCaseTest extends TestCase
{
    private PurchaseOrderUpdateClientInterface&MockInterface $client;

    private LoggerInterface&MockInterface $logger;

    private ChangePurchaseOrderStatusUseCase $useCase;

    private Guid $purchaseGuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(PurchaseOrderUpdateClientInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new ChangePurchaseOrderStatusUseCase($this->client, $this->logger);
        $this->purchaseGuid = Guid::fromTrusted('a1b2c3d4-e5f6-7890-abcd-ef1234567890');
    }

    /*
    |--------------------------------------------------------------------------
    | Valid Transitions — API is Called
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('validTransitionProvider')]
    public function valid_transition_calls_change_purchase_order_status(
        PurchaseOrderStatus $currentStatus,
        PurchaseOrderStatus $targetStatus,
    ): void {
        $this->client
            ->shouldReceive('changePurchaseOrderStatus')
            ->once()
            ->with($this->purchaseGuid, $targetStatus);

        $this->useCase->execute($this->purchaseGuid, $currentStatus, $targetStatus);
    }

    /**
     * @return array<string, array{PurchaseOrderStatus, PurchaseOrderStatus}>
     */
    public static function validTransitionProvider(): array
    {
        return [
            'PENDING→OPEN' => [PurchaseOrderStatus::Pending, PurchaseOrderStatus::Open],
            'OPEN→PARTIAL' => [PurchaseOrderStatus::Open, PurchaseOrderStatus::Partial],
            'OPEN→DELIVERED' => [PurchaseOrderStatus::Open, PurchaseOrderStatus::Delivered],
            'PARTIAL→DELIVERED' => [PurchaseOrderStatus::Partial, PurchaseOrderStatus::Delivered],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Invalid Transitions — Exception Thrown, No API Call
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('invalidTransitionProvider')]
    public function invalid_transition_throws_exception_without_calling_api(
        PurchaseOrderStatus $currentStatus,
        PurchaseOrderStatus $targetStatus,
    ): void {
        $this->client->shouldNotReceive('changePurchaseOrderStatus');

        $this->expectException(InvalidPurchaseOrderStatusTransitionException::class);
        $this->expectExceptionMessage('Invalid purchase order status transition');

        $this->useCase->execute($this->purchaseGuid, $currentStatus, $targetStatus);
    }

    /**
     * @return array<string, array{PurchaseOrderStatus, PurchaseOrderStatus}>
     */
    public static function invalidTransitionProvider(): array
    {
        return [
            'PENDING→PARTIAL (skip)' => [PurchaseOrderStatus::Pending, PurchaseOrderStatus::Partial],
            'PENDING→DELIVERED (skip)' => [PurchaseOrderStatus::Pending, PurchaseOrderStatus::Delivered],
            'OPEN→PENDING (backwards)' => [PurchaseOrderStatus::Open, PurchaseOrderStatus::Pending],
            'DELIVERED→PENDING' => [PurchaseOrderStatus::Delivered, PurchaseOrderStatus::Pending],
            'DELIVERED→OPEN' => [PurchaseOrderStatus::Delivered, PurchaseOrderStatus::Open],
            'DELIVERED→PARTIAL' => [PurchaseOrderStatus::Delivered, PurchaseOrderStatus::Partial],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Exception Properties
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function invalid_transition_exception_carries_from_and_to_status(): void
    {
        $this->client->shouldNotReceive('changePurchaseOrderStatus');

        try {
            $this->useCase->execute(
                $this->purchaseGuid,
                PurchaseOrderStatus::Pending,
                PurchaseOrderStatus::Delivered,
            );
            $this->fail('Expected InvalidPurchaseOrderStatusTransitionException was not thrown');
        } catch (InvalidPurchaseOrderStatusTransitionException $e) {
            $this->assertSame(PurchaseOrderStatus::Pending, $e->from);
            $this->assertSame(PurchaseOrderStatus::Delivered, $e->to);
        }
    }
}
