<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\GoogleAds;

use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Infrastructure\GoogleAds\GoogleAdsClient;
use App\Infrastructure\GoogleAds\GoogleAdsTransport;
use Google\Ads\GoogleAds\V22\Common\Metrics;
use Google\Ads\GoogleAds\V22\Common\Segments;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V22\Resources\Campaign as GoogleCampaign;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use Google\ApiCore\PagedListResponse;
use Google\ApiCore\ValidationException;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GoogleAdsClient Unit Tests.
 *
 * Tests the business logic layer for the Google Ads API, covering:
 * - GAQL query construction with correct date/filters
 * - Response transformation to domain value objects
 * - Exception propagation from transport layer
 *
 * Note: Exception translation tests are in GoogleAdsTransportTest.
 * This class focuses on query construction and transformation.
 */
#[CoversClass(GoogleAdsClient::class)]
final class GoogleAdsClientTest extends TestCase
{
    private GoogleAdsTransport&MockInterface $mockTransport;
    private GoogleAdsClient $client;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockTransport = Mockery::mock(GoogleAdsTransport::class);
        $this->client = new GoogleAdsClient($this->mockTransport);
    }

    /*
    |--------------------------------------------------------------------------
    | getDailyCampaignMetrics Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_campaign_metrics_for_single_result(): void
    {
        $row = $this->createMockCampaignMetricsRow(
            campaignId: 123,
            campaignName: 'Test Campaign',
            costMicros: 50_250_000,
            clicks: 150,
            impressions: 7500,
            conversions: 10.5,
            date: '2024-05-10',
        );

        $this->mockTransportSearch($this->createPagedResponse([$row]));

        $result = $this->client->getDailyCampaignMetrics('2024-05-10');

        $this->assertCount(1, $result);
        $this->assertInstanceOf(CampaignMetrics::class, $result[0]);
        $this->assertSame(123, $result[0]->campaignId);
        $this->assertSame('Test Campaign', $result[0]->campaignName);
        $this->assertSame(50.25, $result[0]->costInPounds);
        $this->assertSame(150, $result[0]->clicks);
        $this->assertSame(7500, $result[0]->impressions);
        $this->assertSame(10.5, $result[0]->conversions);
        $this->assertSame('2024-05-10', $result[0]->date);
    }

    #[Test]
    public function it_returns_campaign_metrics_for_multiple_results(): void
    {
        $row1 = $this->createMockCampaignMetricsRow(
            campaignId: 111,
            campaignName: 'Campaign 1',
            costMicros: 10_000_000,
            clicks: 50,
            impressions: 2500,
            conversions: 5.0,
            date: '2024-05-10',
        );
        $row2 = $this->createMockCampaignMetricsRow(
            campaignId: 222,
            campaignName: 'Campaign 2',
            costMicros: 20_000_000,
            clicks: 100,
            impressions: 5000,
            conversions: 10.0,
            date: '2024-05-10',
        );
        $row3 = $this->createMockCampaignMetricsRow(
            campaignId: 333,
            campaignName: 'Campaign 3',
            costMicros: 30_000_000,
            clicks: 150,
            impressions: 7500,
            conversions: 15.0,
            date: '2024-05-10',
        );

        $this->mockTransportSearch($this->createPagedResponse([$row1, $row2, $row3]));

        $result = $this->client->getDailyCampaignMetrics('2024-05-10');

        $this->assertCount(3, $result);
        $this->assertSame(111, $result[0]->campaignId);
        $this->assertSame(222, $result[1]->campaignId);
        $this->assertSame(333, $result[2]->campaignId);
    }

    #[Test]
    public function it_returns_empty_array_when_no_campaign_metrics(): void
    {
        $this->mockTransportSearch($this->createPagedResponse([]));

        $result = $this->client->getDailyCampaignMetrics('2024-05-10');

        $this->assertSame([], $result);
    }

    #[Test]
    public function it_constructs_gaql_query_with_correct_date(): void
    {
        $this->mockTransport
            ->shouldReceive('search')
            ->once()
            ->withArgs(static fn(string $query): bool => \str_contains($query, "WHERE segments.date = '2024-05-10'")
                    && \str_contains($query, 'SELECT campaign.id')
                    && \str_contains($query, 'campaign.name')
                    && \str_contains($query, 'metrics.cost_micros')
                    && \str_contains($query, 'metrics.clicks')
                    && \str_contains($query, 'metrics.impressions')
                    && \str_contains($query, 'metrics.conversions')
                    && \str_contains($query, 'FROM campaign'))
            ->andReturn($this->createPagedResponse([]));

        $this->client->getDailyCampaignMetrics('2024-05-10');
    }

    #[Test]
    public function it_propagates_external_service_unavailable_exception(): void
    {
        $exception = new ExternalServiceUnavailableException('Google Ads', 60);

        $this->mockTransport
            ->shouldReceive('search')
            ->andThrow($exception);

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Google Ads' is unavailable");

        $this->client->getDailyCampaignMetrics('2024-05-10');
    }

    #[Test]
    public function it_propagates_invalid_api_request_exception(): void
    {
        $exception = new InvalidApiRequestException('Google Ads', 'Invalid GAQL query');

        $this->mockTransport
            ->shouldReceive('search')
            ->andThrow($exception);

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('Invalid GAQL query');

        $this->client->getDailyCampaignMetrics('2024-05-10');
    }

    /*
    |--------------------------------------------------------------------------
    | getCampaigns Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_campaigns_for_single_result(): void
    {
        $row = $this->createMockCampaignRow(
            campaignId: 456,
            campaignName: 'Active Campaign',
            status: CampaignStatus::ENABLED,
        );

        $this->mockTransportSearch($this->createPagedResponse([$row]));

        $result = $this->client->getCampaigns();

        $this->assertCount(1, $result);
        $this->assertInstanceOf(Campaign::class, $result[0]);
        $this->assertSame(456, $result[0]->id);
        $this->assertSame('Active Campaign', $result[0]->name);
        $this->assertSame('ENABLED', $result[0]->status);
    }

    #[Test]
    public function it_returns_campaigns_for_multiple_results(): void
    {
        $row1 = $this->createMockCampaignRow(111, 'Campaign A', CampaignStatus::ENABLED);
        $row2 = $this->createMockCampaignRow(222, 'Campaign B', CampaignStatus::PAUSED);
        $row3 = $this->createMockCampaignRow(333, 'Campaign C', CampaignStatus::ENABLED);

        $this->mockTransportSearch($this->createPagedResponse([$row1, $row2, $row3]));

        $result = $this->client->getCampaigns();

        $this->assertCount(3, $result);
        $this->assertSame(111, $result[0]->id);
        $this->assertSame(222, $result[1]->id);
        $this->assertSame(333, $result[2]->id);
        $this->assertSame('ENABLED', $result[0]->status);
        $this->assertSame('PAUSED', $result[1]->status);
    }

    #[Test]
    public function it_returns_empty_array_when_no_campaigns(): void
    {
        $this->mockTransportSearch($this->createPagedResponse([]));

        $result = $this->client->getCampaigns();

        $this->assertSame([], $result);
    }

    #[Test]
    public function it_constructs_gaql_query_for_campaigns_correctly(): void
    {
        $this->mockTransport
            ->shouldReceive('search')
            ->once()
            ->withArgs(static fn(string $query): bool => \str_contains($query, 'SELECT campaign.id')
                    && \str_contains($query, 'campaign.name')
                    && \str_contains($query, 'campaign.status')
                    && \str_contains($query, 'FROM campaign')
                    && \str_contains($query, "WHERE campaign.status != 'REMOVED'")
                    && \str_contains($query, 'ORDER BY campaign.id'))
            ->andReturn($this->createPagedResponse([]));

        $this->client->getCampaigns();
    }

    #[Test]
    public function it_propagates_exception_from_get_campaigns(): void
    {
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $this->mockTransport
            ->shouldReceive('search')
            ->andThrow($exception);

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->getCampaigns();
    }

    /*
    |--------------------------------------------------------------------------
    | ValidationException During Iteration Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_translates_validation_exception_during_iteration(): void
    {
        $validationException = new ValidationException('Invalid page token');

        $response = Mockery::mock(PagedListResponse::class);
        $response->shouldReceive('iterateAllElements')
            ->andThrow($validationException);

        $this->mockTransport
            ->shouldReceive('search')
            ->andReturn($response);

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage('Google Ads');

        $this->client->getDailyCampaignMetrics('2024-05-10');
    }

    #[Test]
    public function it_logs_error_when_validation_exception_occurs_during_iteration(): void
    {
        $validationException = new ValidationException('Serialization error');

        $response = Mockery::mock(PagedListResponse::class);
        $response->shouldReceive('iterateAllElements')
            ->andThrow($validationException);

        $this->mockTransport
            ->shouldReceive('search')
            ->andReturn($response);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'iteration failed')
                    && \array_key_exists('error', $context)
                    && \str_contains($context['error'], 'Serialization error'));

        try {
            $this->client->getCampaigns();
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    #[Test]
    public function it_preserves_original_validation_exception_as_previous(): void
    {
        $validationException = new ValidationException('Page token expired');

        $response = Mockery::mock(PagedListResponse::class);
        $response->shouldReceive('iterateAllElements')
            ->andThrow($validationException);

        $this->mockTransport
            ->shouldReceive('search')
            ->andReturn($response);

        try {
            $this->client->getDailyCampaignMetrics('2024-05-10');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame($validationException, $e->getPrevious());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Mock transport search to return a response.
     */
    private function mockTransportSearch(PagedListResponse&MockInterface $response): void
    {
        $this->mockTransport
            ->shouldReceive('search')
            ->once()
            ->andReturn($response);
    }

    /**
     * Create a mock PagedListResponse with given rows.
     *
     * @param list<GoogleAdsRow> $rows
     */
    private function createPagedResponse(array $rows): PagedListResponse&MockInterface
    {
        $response = Mockery::mock(PagedListResponse::class);
        $response->shouldReceive('iterateAllElements')->andReturn($rows);

        return $response;
    }

    /**
     * Create a mock GoogleAdsRow for campaign metrics.
     */
    private function createMockCampaignMetricsRow(
        int $campaignId,
        string $campaignName,
        int $costMicros,
        int $clicks,
        int $impressions,
        float $conversions,
        string $date,
    ): GoogleAdsRow&MockInterface {
        $campaign = Mockery::mock(GoogleCampaign::class);
        $campaign->shouldReceive('getId')->andReturn($campaignId);
        $campaign->shouldReceive('getName')->andReturn($campaignName);

        $metrics = Mockery::mock(Metrics::class);
        $metrics->shouldReceive('getCostMicros')->andReturn($costMicros);
        $metrics->shouldReceive('getClicks')->andReturn($clicks);
        $metrics->shouldReceive('getImpressions')->andReturn($impressions);
        $metrics->shouldReceive('getConversions')->andReturn($conversions);

        $segments = Mockery::mock(Segments::class);
        $segments->shouldReceive('getDate')->andReturn($date);

        $row = Mockery::mock(GoogleAdsRow::class);
        $row->shouldReceive('getCampaign')->andReturn($campaign);
        $row->shouldReceive('getMetrics')->andReturn($metrics);
        $row->shouldReceive('getSegments')->andReturn($segments);

        return $row;
    }

    /**
     * Create a mock GoogleAdsRow for campaign list.
     */
    private function createMockCampaignRow(
        int $campaignId,
        string $campaignName,
        int $status,
    ): GoogleAdsRow&MockInterface {
        $campaign = Mockery::mock(GoogleCampaign::class);
        $campaign->shouldReceive('getId')->andReturn($campaignId);
        $campaign->shouldReceive('getName')->andReturn($campaignName);
        $campaign->shouldReceive('getStatus')->andReturn($status);

        $row = Mockery::mock(GoogleAdsRow::class);
        $row->shouldReceive('getCampaign')->andReturn($campaign);

        return $row;
    }
}
