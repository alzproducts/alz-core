<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\CustomerClientInterface;
use App\Application\Contracts\Shopwired\CustomerRepositoryInterface;
use App\Application\Results\SaveManyResult;
use App\Application\Shopwired\UseCases\SyncCustomersUseCase;
use App\Domain\Customer\ValueObjects\Customer;
use DateTimeImmutable;
use Generator;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * SyncCustomersUseCase Unit Tests.
 *
 * Tests generator-based customer sync orchestration:
 * - Empty customers handling
 * - Buffer management (flush every 10 pages)
 * - Continue-on-failure semantics
 * - Final buffer flush
 * - Trade/non-trade page limits
 */
#[CoversClass(SyncCustomersUseCase::class)]
final class SyncCustomersUseCaseTest extends TestCase
{
    private CustomerClientInterface&MockInterface $customerClient;

    private CustomerRepositoryInterface&MockInterface $customerRepository;

    private LoggerInterface&MockInterface $logger;

    private SyncCustomersUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerClient = Mockery::mock(CustomerClientInterface::class);
        $this->customerRepository = Mockery::mock(CustomerRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new SyncCustomersUseCase(
            $this->customerClient,
            $this->customerRepository,
            $this->logger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Empty Customers Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_returns_empty_result_when_no_customers_found(): void
    {
        $this->customerClient
            ->shouldReceive('iterateCustomerBatches')
            ->once()
            ->with(null, null)
            ->andReturn($this->emptyGenerator());

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Starting full customer sync from ShopWired');

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Customer sync completed: no customers found in ShopWired');

        $result = $this->useCase->execute();

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->saved);
        $this->assertSame(0, $result->failed);
    }

    /*
    |--------------------------------------------------------------------------
    | Single Page Branch (No Buffer Flush, Only Final Flush)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_flushes_remaining_buffer_when_less_than_batch_size(): void
    {
        $customers = [$this->createCustomer(1), $this->createCustomer(2)];

        $this->customerClient
            ->shouldReceive('iterateCustomerBatches')
            ->once()
            ->with(1, 1)
            ->andReturn($this->singlePageGenerator($customers));

        $this->customerRepository
            ->shouldReceive('saveCustomersBulk')
            ->once()
            ->with($customers)
            ->andReturn(SaveManyResult::success(2));

        $this->logger->shouldReceive('info')->with(Mockery::pattern('/Starting.*customer sync/'));
        $this->logger->shouldReceive('debug')->with('Fetched customer page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->with('Flushing customer batch to database', Mockery::type('array'));
        $this->logger->shouldReceive('info')->with('Customer sync completed', Mockery::type('array'));

        $result = $this->useCase->execute(1, 1);

        $this->assertSame(2, $result->fetched);
        $this->assertSame(2, $result->saved);
        $this->assertSame(0, $result->failed);
        $this->assertTrue($result->allSaved());
    }

    /*
    |--------------------------------------------------------------------------
    | Buffer Flush Branch (10+ Pages)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_flushes_buffer_after_10_pages(): void
    {
        // Create 10 pages with 2 customers each = 20 customers total
        $customersPerPage = [];
        for ($i = 0; $i < 10; $i++) {
            $customersPerPage[$i] = [$this->createCustomer($i * 2 + 1), $this->createCustomer($i * 2 + 2)];
        }

        $this->customerClient
            ->shouldReceive('iterateCustomerBatches')
            ->once()
            ->with(10, 10)
            ->andReturn($this->multiPageGenerator($customersPerPage));

        // Should flush once after 10 pages (20 customers)
        $this->customerRepository
            ->shouldReceive('saveCustomersBulk')
            ->once()
            ->with(Mockery::on(static fn(array $customers) => \count($customers) === 20))
            ->andReturn(SaveManyResult::success(20));

        $this->logger->shouldReceive('info')->with(Mockery::pattern('/Starting.*customer sync/'));
        $this->logger->shouldReceive('debug')->times(10)->with('Fetched customer page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->once()->with('Flushing customer batch to database', Mockery::type('array'));
        $this->logger->shouldReceive('info')->with('Customer sync completed', Mockery::type('array'));

        $result = $this->useCase->execute(10, 10);

        $this->assertSame(20, $result->fetched);
        $this->assertSame(20, $result->saved);
        $this->assertSame(0, $result->failed);
    }

    /*
    |--------------------------------------------------------------------------
    | Partial Failure Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_continues_on_partial_failure_and_logs_error(): void
    {
        $customers = [$this->createCustomer(1), $this->createCustomer(2), $this->createCustomer(3)];
        $failedRefs = [2, 3];

        $this->customerClient
            ->shouldReceive('iterateCustomerBatches')
            ->once()
            ->with(1, 1)
            ->andReturn($this->singlePageGenerator($customers));

        $this->customerRepository
            ->shouldReceive('saveCustomersBulk')
            ->once()
            ->andReturn(new SaveManyResult(succeeded: 1, failed: 2, failedReferences: $failedRefs));

        $this->logger->shouldReceive('info')->with(Mockery::pattern('/Starting.*customer sync/'));
        $this->logger->shouldReceive('debug')->with('Fetched customer page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->with('Flushing customer batch to database', Mockery::type('array'));
        $this->logger
            ->shouldReceive('error')
            ->once()
            ->with('Failed to save some customers to database', Mockery::on(static fn(array $context) => $context['failed_count'] === 2
                    && $context['failed_ids'] === $failedRefs));
        $this->logger->shouldReceive('info')->with('Customer sync completed', Mockery::type('array'));

        $result = $this->useCase->execute(1, 1);

        $this->assertSame(3, $result->fetched);
        $this->assertSame(1, $result->saved);
        $this->assertSame(2, $result->failed);
        $this->assertSame($failedRefs, $result->failedReferences);
        $this->assertTrue($result->hasFailures());
    }

    /*
    |--------------------------------------------------------------------------
    | Multiple Batches with Final Flush
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_handles_multiple_batches_plus_final_flush(): void
    {
        // Create 12 pages: first 10 pages flush at batch boundary, last 2 flush at end
        $customersPerPage = [];
        for ($i = 0; $i < 12; $i++) {
            $customersPerPage[$i] = [$this->createCustomer($i + 1)];
        }

        $this->customerClient
            ->shouldReceive('iterateCustomerBatches')
            ->once()
            ->with(null, null)
            ->andReturn($this->multiPageGenerator($customersPerPage));

        // First flush after 10 pages (10 customers)
        $this->customerRepository
            ->shouldReceive('saveCustomersBulk')
            ->once()
            ->with(Mockery::on(static fn(array $customers) => \count($customers) === 10))
            ->andReturn(SaveManyResult::success(10));

        // Final flush with remaining 2 customers
        $this->customerRepository
            ->shouldReceive('saveCustomersBulk')
            ->once()
            ->with(Mockery::on(static fn(array $customers) => \count($customers) === 2))
            ->andReturn(SaveManyResult::success(2));

        $this->logger->shouldReceive('info')->with(Mockery::pattern('/Starting.*customer sync/'));
        $this->logger->shouldReceive('debug')->times(12)->with('Fetched customer page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->times(2)->with('Flushing customer batch to database', Mockery::type('array'));
        $this->logger->shouldReceive('info')->with('Customer sync completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(12, $result->fetched);
        $this->assertSame(12, $result->saved);
        $this->assertSame(0, $result->failed);
    }

    /*
    |--------------------------------------------------------------------------
    | Page Limit Parameters
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_passes_page_limits_to_client(): void
    {
        $this->customerClient
            ->shouldReceive('iterateCustomerBatches')
            ->once()
            ->with(5, 10)
            ->andReturn($this->emptyGenerator());

        $this->logger->shouldReceive('info')->with('Starting quick (5 trade, 10 non-trade pages) customer sync from ShopWired');
        $this->logger->shouldReceive('info')->with('Customer sync completed: no customers found in ShopWired');

        $result = $this->useCase->execute(5, 10);

        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function execute_handles_trade_only_limit(): void
    {
        $this->customerClient
            ->shouldReceive('iterateCustomerBatches')
            ->once()
            ->with(3, null)
            ->andReturn($this->emptyGenerator());

        $this->logger->shouldReceive('info')->with('Starting quick (3 trade, all non-trade pages) customer sync from ShopWired');
        $this->logger->shouldReceive('info')->with('Customer sync completed: no customers found in ShopWired');

        $result = $this->useCase->execute(3, null);

        $this->assertTrue($result->isEmpty());
    }

    /*
    |--------------------------------------------------------------------------
    | Test Fixtures & Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return Generator<int, list<Customer>, mixed, void>
     */
    private function emptyGenerator(): Generator
    {
        yield from [];
    }

    /**
     * @param list<Customer> $customers
     *
     * @return Generator<int, list<Customer>, mixed, void>
     */
    private function singlePageGenerator(array $customers): Generator
    {
        yield 1 => $customers;
    }

    /**
     * @param array<int, list<Customer>> $customersPerPage Page number => customers
     *
     * @return Generator<int, list<Customer>, mixed, void>
     */
    private function multiPageGenerator(array $customersPerPage): Generator
    {
        foreach ($customersPerPage as $pageNumber => $customers) {
            yield $pageNumber + 1 => $customers;
        }
    }

    private function createCustomer(int $id): Customer
    {
        return new Customer(
            id: $id,
            createdAt: new DateTimeImmutable(),
            email: "customer{$id}@example.com",
            firstName: 'Test',
            lastName: "Customer{$id}",
            companyName: null,
            isTrade: false,
            isActive: true,
            isCreditEnabled: null,
            phone: null,
            mobilePhone: null,
            acceptsMarketing: false,
            address: null,
            notes: null,
            customFields: [],
        );
    }
}
