<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Linnworks\UseCases\PurchaseOrder;

use App\Application\Contracts\Linnworks\PurchaseOrderClientInterface;
use App\Application\Linnworks\DTOs\PurchaseOrder\DesiredExtendedPropertyDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\PurchaseOrderLineItemDTO;
use App\Application\Linnworks\UseCases\PurchaseOrder\CreatePurchaseOrderCommand;
use App\Application\Linnworks\UseCases\PurchaseOrder\CreatePurchaseOrderUseCase;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderReference;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\TaxRate;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * CreatePurchaseOrderUseCase Unit Tests.
 *
 * Tests the orchestration of create-initial → add-items → add-EPs,
 * and the cleanup-on-failure rollback semantics.
 */
#[CoversClass(CreatePurchaseOrderUseCase::class)]
final class CreatePurchaseOrderUseCaseTest extends TestCase
{
    private PurchaseOrderClientInterface&MockInterface $client;

    private LoggerInterface&MockInterface $logger;

    private CreatePurchaseOrderUseCase $useCase;

    private Guid $supplierGuid;

    private Guid $locationGuid;

    private PurchaseOrderReference $reference;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(PurchaseOrderClientInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new CreatePurchaseOrderUseCase(
            $this->client,
            $this->logger,
        );

        $this->supplierGuid = Guid::fromTrusted('a1b2c3d4-e5f6-7890-abcd-ef1234567890');
        $this->locationGuid = Guid::fromTrusted('b2c3d4e5-f6a7-8901-bcde-f12345678901');
        $this->reference = PurchaseOrderReference::fromString('PO-TEST-001');
    }

    /*
    |--------------------------------------------------------------------------
    | Successful Creation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_calls_create_initial_then_add_item_for_each_item_and_returns_purchase_id(): void
    {
        $item1 = new PurchaseOrderLineItemDTO(fkStockItemId: 'stock-1', quantity: 10, cost: 5.00, taxRate: TaxRate::standard());
        $item2 = new PurchaseOrderLineItemDTO(fkStockItemId: 'stock-2', quantity: 5, cost: 12.50, taxRate: TaxRate::standard());

        $command = $this->makeCommand(
            items: [$item1, $item2],
        );

        $purchaseGuid = Guid::fromTrusted('c3d4e5f6-a7b8-9012-cdef-123456789012');

        $this->client
            ->shouldReceive('createPurchaseOrderInitial')
            ->once()
            ->andReturn($purchaseGuid);

        $this->client
            ->shouldReceive('addPurchaseOrderItem')
            ->twice();

        $result = $this->useCase->execute($command);

        $this->assertSame($purchaseGuid, $result);
    }

    #[Test]
    public function execute_with_no_items_returns_purchase_id_without_adding_items(): void
    {
        $command = $this->makeCommand(
            items: [],
        );

        $purchaseGuid = Guid::fromTrusted('d4e5f6a7-b8c9-0123-defa-234567890123');

        $this->client
            ->shouldReceive('createPurchaseOrderInitial')
            ->once()
            ->andReturn($purchaseGuid);

        $this->client
            ->shouldNotReceive('addPurchaseOrderItem');

        $result = $this->useCase->execute($command);

        $this->assertSame($purchaseGuid, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | Extended Properties
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_adds_extended_properties_when_provided(): void
    {
        $ep = new DesiredExtendedPropertyDTO('IsDropship', 'true');

        $command = $this->makeCommand(
            items: [],
            extendedProperties: [$ep],
        );

        $purchaseGuid = Guid::fromTrusted('e5f6a7b8-c9d0-1234-efab-345678901234');

        $this->client
            ->shouldReceive('createPurchaseOrderInitial')
            ->once()
            ->andReturn($purchaseGuid);

        $this->client
            ->shouldReceive('addPurchaseOrderExtendedProperties')
            ->once()
            ->with($purchaseGuid, [$ep]);

        $result = $this->useCase->execute($command);

        $this->assertSame($purchaseGuid, $result);
    }

    #[Test]
    public function execute_does_not_call_add_extended_properties_when_none_provided(): void
    {
        $command = $this->makeCommand(
            items: [],
            extendedProperties: [],
        );

        $purchaseGuid = Guid::fromTrusted('f6a7b8c9-d0e1-2345-fabc-456789012345');

        $this->client
            ->shouldReceive('createPurchaseOrderInitial')
            ->once()
            ->andReturn($purchaseGuid);

        $this->client
            ->shouldNotReceive('addPurchaseOrderExtendedProperties');

        $this->useCase->execute($command);
    }

    /*
    |--------------------------------------------------------------------------
    | Cleanup on Item Failure
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_deletes_purchase_order_and_rethrows_when_add_item_fails(): void
    {
        $item = new PurchaseOrderLineItemDTO(fkStockItemId: 'stock-1', quantity: 1, cost: 10.00, taxRate: TaxRate::standard());

        $command = $this->makeCommand(
            items: [$item],
        );

        $purchaseGuid = Guid::fromTrusted('a7b8c9d0-e1f2-3456-abcd-567890123456');
        $exception = new InvalidApiRequestException('linnworks', 'Invalid item parameters');

        $this->client
            ->shouldReceive('createPurchaseOrderInitial')
            ->once()
            ->andReturn($purchaseGuid);

        $this->client
            ->shouldReceive('addPurchaseOrderItem')
            ->once()
            ->andThrow($exception);

        $this->client
            ->shouldReceive('deletePurchaseOrder')
            ->once()
            ->with($purchaseGuid);

        $this->logger
            ->shouldReceive('warning')
            ->once()
            ->with('Deleted partially-created purchase order after failure', Mockery::type('array'));

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('linnworks: Invalid item parameters');

        $this->useCase->execute($command);
    }

    #[Test]
    public function execute_logs_error_and_rethrows_original_when_cleanup_also_fails(): void
    {
        $item = new PurchaseOrderLineItemDTO(fkStockItemId: 'stock-1', quantity: 1, cost: 10.00, taxRate: TaxRate::standard());

        $command = $this->makeCommand(
            items: [$item],
        );

        $purchaseGuid = Guid::fromTrusted('b8c9d0e1-f2a3-4567-bcde-678901234567');
        $originalException = new InvalidApiRequestException('linnworks', 'Item error');
        $cleanupException = new RuntimeException('Delete also failed');

        $this->client
            ->shouldReceive('createPurchaseOrderInitial')
            ->once()
            ->andReturn($purchaseGuid);

        $this->client
            ->shouldReceive('addPurchaseOrderItem')
            ->once()
            ->andThrow($originalException);

        $this->client
            ->shouldReceive('deletePurchaseOrder')
            ->once()
            ->andThrow($cleanupException);

        $this->logger
            ->shouldReceive('error')
            ->once()
            ->with('Failed to clean up partially-created purchase order', Mockery::on(
                static fn(array $context): bool => $context['purchaseId'] === 'b8c9d0e1-f2a3-4567-bcde-678901234567'
                    && $context['originalError'] === 'linnworks: Item error'
                    && $context['cleanupError'] === 'Delete also failed',
            ));

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('linnworks: Item error');

        $this->useCase->execute($command);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param list<PurchaseOrderLineItemDTO> $items
     * @param list<DesiredExtendedPropertyDTO> $extendedProperties
     */
    private function makeCommand(
        array $items = [],
        array $extendedProperties = [],
    ): CreatePurchaseOrderCommand {
        return new CreatePurchaseOrderCommand(
            fkSupplierId: $this->supplierGuid,
            fkLocationId: $this->locationGuid,
            reference: $this->reference,
            items: $items,
            currency: 'GBP',
            supplierReferenceNumber: '',
            unitAmountTaxIncludedType: null,
            dateOfPurchase: null,
            postagePaid: Money::exclusive(0.00),
            shippingTaxRate: TaxRate::standard(),
            extendedProperties: $extendedProperties,
        );
    }
}
