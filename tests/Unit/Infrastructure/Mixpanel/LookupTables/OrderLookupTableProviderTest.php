<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Mixpanel\LookupTables;

use App\Domain\Catalog\Order\ValueObjects\OrderAnalyticsHash;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Infrastructure\Database\DatabaseGateway;
use App\Infrastructure\Mixpanel\LookupTables\OrderLookupTableProvider;
use App\Infrastructure\Mixpanel\MixpanelConfig;
use Illuminate\Database\Connection;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for OrderLookupTableProvider.
 *
 * Tests row transformation logic and bug period handling.
 * Per TestingStrategy.md: mock dependencies, test business logic.
 */
#[CoversClass(OrderLookupTableProvider::class)]
final class OrderLookupTableProviderTest extends TestCase
{
    private const string TEST_ANALYTICS_SALT = 'test-salt-for-hashing';

    private MixpanelConfig $config;

    private DatabaseGateway&MockInterface $database;

    private Connection&MockInterface $connection;

    private OrderLookupTableProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new MixpanelConfig(
            dataApiBaseUrl: 'https://api-eu.mixpanel.com',
            exportApiBaseUrl: 'https://data-eu.mixpanel.com',
            serviceAccountUsername: 'test-user',
            serviceAccountPassword: 'test-pass',
            projectId: 'test-project',
            analyticsSalt: self::TEST_ANALYTICS_SALT,
            lookupTableIds: ['order_enrichment' => 'table-uuid'],
        );

        $this->database = Mockery::mock(DatabaseGateway::class);
        $this->connection = Mockery::mock(Connection::class);

        $this->database
            ->shouldReceive('connection')
            ->andReturn($this->connection);

        $this->provider = new OrderLookupTableProvider($this->config, $this->database);
    }

    // ========================================================================
    // Metadata Tests
    // ========================================================================

    #[Test]
    public function it_returns_correct_table_key(): void
    {
        self::assertSame('order_enrichment', $this->provider->getTableKey());
    }

    #[Test]
    public function it_returns_correct_source_name(): void
    {
        self::assertSame('ShopWired Orders', $this->provider->getSourceName());
    }

    #[Test]
    public function it_returns_correct_headers(): void
    {
        self::assertSame(
            [
                'order_id_hashed',
                'user_is_credit',
                'user_account_created_at',
                'user_company_name',
                'user_is_trade',
                'order_is_first_order',
                'user_total_orders',
                'user_lifetime_value',
            ],
            $this->provider->getHeaders(),
        );
    }

    // ========================================================================
    // Row Transformation Tests
    // ========================================================================

    #[Test]
    public function it_transforms_single_order_to_row_outside_bug_period(): void
    {
        $dbRow = $this->createDbRow(
            reference: '12345',
            orderPlacedAt: '2024-06-15 10:30:00', // Before bug period
            userIsCredit: true,
            userAccountCreatedAt: '2024-01-01 00:00:00',
            userCompanyName: 'Test Company',
            userIsTrade: false,
            orderIsFirstOrder: true,
            userTotalOrders: '3',
            userLifetimeValue: '150.50',
        );

        $this->mockQueryReturns([$dbRow]);

        $rows = $this->provider->fetchRows();

        // Only one row - outside bug period
        self::assertCount(1, $rows);

        $expectedHash = OrderAnalyticsHash::fromReference(12345, self::TEST_ANALYTICS_SALT)->value;

        self::assertSame($expectedHash, $rows[0][0]);
        self::assertSame('true', $rows[0][1]);  // user_is_credit
        self::assertSame('2024-01-01 00:00:00', $rows[0][2]); // user_account_created_at
        self::assertSame('Test Company', $rows[0][3]); // user_company_name
        self::assertSame('false', $rows[0][4]); // user_is_trade
        self::assertSame('true', $rows[0][5]);  // order_is_first_order
        self::assertSame('3', $rows[0][6]);     // user_total_orders
        self::assertSame('150.50', $rows[0][7]); // user_lifetime_value
    }

    #[Test]
    public function it_creates_duplicate_row_for_bug_period_order(): void
    {
        $orderPlacedAt = '2025-10-15 14:30:00'; // Within bug period
        $dbRow = $this->createDbRow(
            reference: '99999',
            orderPlacedAt: $orderPlacedAt,
            userIsCredit: false,
            userAccountCreatedAt: null,
            userCompanyName: null,
            userIsTrade: true,
            orderIsFirstOrder: false,
            userTotalOrders: '5',
            userLifetimeValue: '500.00',
        );

        $this->mockQueryReturns([$dbRow]);

        $rows = $this->provider->fetchRows();

        // Two rows - one standard, one with fallback hash
        self::assertCount(2, $rows);

        // Standard hash
        $expectedStandardHash = OrderAnalyticsHash::fromReference(99999, self::TEST_ANALYTICS_SALT)->value;
        self::assertSame($expectedStandardHash, $rows[0][0]);

        // Fallback hash (alz-{timestamp})
        $fallbackSalt = 'alz-' . \strtotime($orderPlacedAt);
        $expectedFallbackHash = \hash('sha256', '99999' . $fallbackSalt);
        self::assertSame($expectedFallbackHash, $rows[1][0]);

        // Both rows should have identical enrichment data
        self::assertSame($rows[0][1], $rows[1][1]); // user_is_credit
        self::assertSame($rows[0][4], $rows[1][4]); // user_is_trade
        self::assertSame($rows[0][7], $rows[1][7]); // user_lifetime_value
    }

    #[Test]
    public function it_handles_bug_period_boundary_start(): void
    {
        // Exactly at bug period start
        $dbRow = $this->createDbRow(
            reference: '1111',
            orderPlacedAt: '2025-09-01 00:00:00',
        );

        $this->mockQueryReturns([$dbRow]);

        $rows = $this->provider->fetchRows();

        // Should get duplicate (bug period inclusive)
        self::assertCount(2, $rows);
    }

    #[Test]
    public function it_handles_bug_period_boundary_end(): void
    {
        // Last moment of bug period
        $dbRow = $this->createDbRow(
            reference: '2222',
            orderPlacedAt: '2026-01-26 23:59:59',
        );

        $this->mockQueryReturns([$dbRow]);

        $rows = $this->provider->fetchRows();

        // Should get duplicate (bug period inclusive)
        self::assertCount(2, $rows);
    }

    #[Test]
    public function it_does_not_duplicate_order_after_bug_period(): void
    {
        // Just after bug period ends
        $dbRow = $this->createDbRow(
            reference: '3333',
            orderPlacedAt: '2026-01-27 00:00:00',
        );

        $this->mockQueryReturns([$dbRow]);

        $rows = $this->provider->fetchRows();

        // Only one row - after bug period
        self::assertCount(1, $rows);
    }

    #[Test]
    public function it_does_not_duplicate_order_before_bug_period(): void
    {
        // Just before bug period starts
        $dbRow = $this->createDbRow(
            reference: '4444',
            orderPlacedAt: '2025-08-31 23:59:59',
        );

        $this->mockQueryReturns([$dbRow]);

        $rows = $this->provider->fetchRows();

        // Only one row - before bug period
        self::assertCount(1, $rows);
    }

    // ========================================================================
    // Field Formatting Tests
    // ========================================================================

    #[Test]
    public function it_formats_boolean_fields_as_strings(): void
    {
        $dbRow = $this->createDbRow(
            userIsCredit: true,
            userIsTrade: true,
            orderIsFirstOrder: true,
        );

        $this->mockQueryReturns([$dbRow]);

        $rows = $this->provider->fetchRows();
        $row = $rows[0];

        self::assertSame('true', $row[1]); // user_is_credit
        self::assertSame('true', $row[4]); // user_is_trade
        self::assertSame('true', $row[5]); // order_is_first_order
    }

    #[Test]
    public function it_formats_false_boolean_fields(): void
    {
        $dbRow = $this->createDbRow(
            userIsCredit: false,
            userIsTrade: false,
            orderIsFirstOrder: false,
        );

        $this->mockQueryReturns([$dbRow]);

        $rows = $this->provider->fetchRows();
        $row = $rows[0];

        self::assertSame('false', $row[1]); // user_is_credit
        self::assertSame('false', $row[4]); // user_is_trade
        self::assertSame('false', $row[5]); // order_is_first_order
    }

    #[Test]
    public function it_handles_null_user_account_created_at(): void
    {
        $dbRow = $this->createDbRow(userAccountCreatedAt: null);

        $this->mockQueryReturns([$dbRow]);

        $rows = $this->provider->fetchRows();

        self::assertSame('', $rows[0][2]); // Empty string for null
    }

    #[Test]
    public function it_handles_null_company_name(): void
    {
        $dbRow = $this->createDbRow(userCompanyName: null);

        $this->mockQueryReturns([$dbRow]);

        $rows = $this->provider->fetchRows();

        self::assertSame('', $rows[0][3]); // Empty string for null
    }

    #[Test]
    public function it_formats_lifetime_value_with_two_decimals(): void
    {
        $dbRow = $this->createDbRow(userLifetimeValue: '1234.5');

        $this->mockQueryReturns([$dbRow]);

        $rows = $this->provider->fetchRows();

        self::assertSame('1234.50', $rows[0][7]); // Padded to 2 decimals
    }

    #[Test]
    public function it_formats_whole_number_lifetime_value(): void
    {
        $dbRow = $this->createDbRow(userLifetimeValue: '500');

        $this->mockQueryReturns([$dbRow]);

        $rows = $this->provider->fetchRows();

        self::assertSame('500.00', $rows[0][7]);
    }

    #[Test]
    public function it_formats_zero_lifetime_value(): void
    {
        $dbRow = $this->createDbRow(userLifetimeValue: '0');

        $this->mockQueryReturns([$dbRow]);

        $rows = $this->provider->fetchRows();

        self::assertSame('0.00', $rows[0][7]);
    }

    // ========================================================================
    // Multiple Orders Tests
    // ========================================================================

    #[Test]
    public function it_handles_mixed_bug_period_and_normal_orders(): void
    {
        $normalOrder = $this->createDbRow(
            reference: '100',
            orderPlacedAt: '2024-06-15 10:00:00', // Before bug period
        );

        $bugPeriodOrder = $this->createDbRow(
            reference: '200',
            orderPlacedAt: '2025-10-15 10:00:00', // In bug period
        );

        $this->mockQueryReturns([$normalOrder, $bugPeriodOrder]);

        $rows = $this->provider->fetchRows();

        // 1 + 2 = 3 rows
        self::assertCount(3, $rows);
    }

    #[Test]
    public function it_returns_empty_array_when_no_orders(): void
    {
        $this->mockQueryReturns([]);

        $rows = $this->provider->fetchRows();

        self::assertSame([], $rows);
    }

    // ========================================================================
    // Exception Propagation Tests
    // ========================================================================

    #[Test]
    public function it_propagates_external_service_unavailable_exception(): void
    {
        $exception = new ExternalServiceUnavailableException('Database');

        $this->database
            ->shouldReceive('query')
            ->once()
            ->andThrow($exception);

        $this->expectExceptionObject($exception);

        $this->provider->fetchRows();
    }

    #[Test]
    public function it_propagates_database_operation_failed_exception(): void
    {
        $exception = new DatabaseOperationFailedException('SELECT', 'Query failed');

        $this->database
            ->shouldReceive('query')
            ->once()
            ->andThrow($exception);

        $this->expectExceptionObject($exception);

        $this->provider->fetchRows();
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    /**
     * Create a mock database row object.
     */
    private function createDbRow(
        string $reference = '12345',
        string $orderPlacedAt = '2024-06-15 10:30:00',
        bool $userIsCredit = false,
        ?string $userAccountCreatedAt = '2024-01-01 00:00:00',
        ?string $userCompanyName = 'Test Co',
        bool $userIsTrade = false,
        bool $orderIsFirstOrder = true,
        string $userTotalOrders = '1',
        string $userLifetimeValue = '100.00',
    ): object {
        return (object) [
            'reference' => $reference,
            'order_placed_at' => $orderPlacedAt,
            'user_is_credit' => $userIsCredit,
            'user_account_created_at' => $userAccountCreatedAt,
            'user_company_name' => $userCompanyName,
            'user_is_trade' => $userIsTrade,
            'order_is_first_order' => $orderIsFirstOrder,
            'user_total_orders' => $userTotalOrders,
            'user_lifetime_value' => $userLifetimeValue,
        ];
    }

    /**
     * Mock the database query to return given rows.
     *
     * @param list<object> $rows
     */
    private function mockQueryReturns(array $rows): void
    {
        $this->connection
            ->shouldReceive('select')
            ->once()
            ->andReturn($rows);

        $this->database
            ->shouldReceive('query')
            ->once()
            ->andReturnUsing(static fn(callable $callback): array => $callback());
    }
}
