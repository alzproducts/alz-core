<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Linnworks\Clients;

use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Clients\DashboardsClient;
use App\Infrastructure\Linnworks\Clients\StockDashboardsClient;
use App\Infrastructure\Linnworks\LinnworksConfig;
use App\Infrastructure\Linnworks\LinnworksHttpTransport;
use App\Infrastructure\Linnworks\LinnworksSession;
use App\Infrastructure\Linnworks\LinnworksSessionManager;
use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integration tests for StockDashboardsClient.
 *
 * Tests the HTTP boundary behavior for Linnworks SQL queries.
 * Per TestingStrategy.md: 1-2 integration tests at HTTP boundary.
 */
#[CoversClass(StockDashboardsClient::class)]
#[CoversClass(DashboardsClient::class)]
final class StockDashboardsClientTest extends TestCase
{
    private const string TEST_SERVER_URL = 'https://eu-ext.linnworks.net';

    private const string TEST_TOKEN = 'test-auth-token';

    private LinnworksSessionManager&MockInterface $sessionManager;

    private StockDashboardsClient $client;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionManager = Mockery::mock(LinnworksSessionManager::class);
        $this->sessionManager->allows('getSession')->andReturn($this->createValidSession());
        $this->sessionManager->allows('invalidate');

        $config = new LinnworksConfig(
            applicationId: 'test-app-id',
            applicationSecret: 'test-app-secret',
            installationToken: 'test-install-token',
        );

        $transport = new LinnworksHttpTransport($config, $this->sessionManager);
        $dashboardsClient = new DashboardsClient($transport);
        $this->client = new StockDashboardsClient($dashboardsClient);
    }

    private function createValidSession(): LinnworksSession
    {
        return new LinnworksSession(
            token: self::TEST_TOKEN,
            serverUrl: self::TEST_SERVER_URL,
            expiresAt: new DateTimeImmutable('+1 hour'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Happy Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_finds_stock_items_by_sku(): void
    {
        Http::fake([
            self::TEST_SERVER_URL . '/Dashboards/ExecuteCustomScriptQuery' => Http::response([
                'IsError' => false,
                'TotalResults' => 2,
                'Columns' => [
                    ['Name' => 'pkStockItemID', 'Type' => 'uniqueidentifier'],
                    ['Name' => 'ItemNumber', 'Type' => 'nvarchar'],
                ],
                'Results' => [
                    [
                        'pkStockItemID' => '550e8400-e29b-41d4-a716-446655440000',
                        'ItemNumber' => 'SKU001',
                    ],
                    [
                        'pkStockItemID' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
                        'ItemNumber' => 'SKU002',
                    ],
                ],
            ]),
        ]);

        $result = $this->client->findStockItemsBySku(['SKU001', 'SKU002']);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(Guid::class, $result['SKU001']);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $result['SKU001']->value);
        $this->assertSame('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $result['SKU002']->value);

        Http::assertSent(function (Request $request) {
            // Verify endpoint
            $this->assertStringEndsWith('/Dashboards/ExecuteCustomScriptQuery', $request->url());

            // Linnworks transport wraps data in 'request' JSON form parameter
            $requestJson = $request->data()['request'] ?? '';
            /** @var array{Script?: string} $parsed */
            $parsed = \json_decode($requestJson, true);
            $sql = $parsed['Script'] ?? '';

            // Verify SQL contains isolation level
            $this->assertStringContainsString(
                'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
                $sql,
            );

            // Verify SQL contains IN clause with SKUs
            $this->assertStringContainsString("('SKU001', 'SKU002')", $sql);

            // Verify auth header
            $this->assertSame([self::TEST_TOKEN], $request->header('Authorization'));

            return true;
        });
    }

    #[Test]
    public function it_returns_empty_array_for_empty_input(): void
    {
        Http::fake();

        $result = $this->client->findStockItemsBySku([]);

        $this->assertSame([], $result);

        // Should not make any HTTP requests
        Http::assertNothingSent();
    }

    #[Test]
    public function it_returns_empty_array_when_no_skus_found(): void
    {
        Http::fake([
            self::TEST_SERVER_URL . '/Dashboards/ExecuteCustomScriptQuery' => Http::response([
                'IsError' => false,
                'TotalResults' => 0,
                'Columns' => [],
                'Results' => [],
            ]),
        ]);

        $result = $this->client->findStockItemsBySku(['NONEXISTENT']);

        $this->assertSame([], $result);
    }

    /*
    |--------------------------------------------------------------------------
    | Error Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_on_query_error(): void
    {
        Http::fake([
            self::TEST_SERVER_URL . '/Dashboards/ExecuteCustomScriptQuery' => Http::response([
                'IsError' => true,
                'TotalResults' => 0,
                'Columns' => [],
                'Results' => [],
            ]),
        ]);

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('SQL query returned error');

        $this->client->findStockItemsBySku(['SKU001']);
    }

    #[Test]
    public function it_throws_on_malformed_response(): void
    {
        Http::fake([
            self::TEST_SERVER_URL . '/Dashboards/ExecuteCustomScriptQuery' => Http::response([
                'UnexpectedField' => 'value',
            ]),
        ]);

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('SQL query response malformed');

        $this->client->findStockItemsBySku(['SKU001']);
    }
}
