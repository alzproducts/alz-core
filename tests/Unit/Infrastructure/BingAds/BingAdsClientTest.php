<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\BingAds;

use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\ValueObjects\DateRange;
use App\Infrastructure\BingAds\BingAdsClient;
use App\Infrastructure\BingAds\BingAdsTransport;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BingAdsClient Unit Tests.
 *
 * Tests the business logic layer for the Bing Ads API, covering:
 * - Ad source identification
 * - Connectivity verification delegation
 * - Campaign metrics retrieval and CSV transformation
 * - Exception propagation from transport layer
 */
#[CoversClass(BingAdsClient::class)]
final class BingAdsClientTest extends TestCase
{
    private BingAdsTransport&MockInterface $mockTransport;
    private BingAdsClient $client;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockTransport = Mockery::mock(BingAdsTransport::class);
        $this->client = new BingAdsClient($this->mockTransport);
    }

    /*
    |--------------------------------------------------------------------------
    | getSource Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_bing_as_ad_source(): void
    {
        $this->assertSame(AdSource::Bing, $this->client->getSource());
    }

    /*
    |--------------------------------------------------------------------------
    | verifyConnectivity Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_verifies_connectivity_by_fetching_account(): void
    {
        $this->mockTransport
            ->shouldReceive('getAccount')
            ->once()
            ->andReturn((object) ['Id' => '123', 'Name' => 'Test Account', 'CurrencyCode' => 'GBP']);

        $this->client->verifyConnectivity();

        // Assert: No exception thrown means connectivity verified
        $this->assertTrue(true);
    }

    #[Test]
    public function it_propagates_authentication_expired_on_connectivity_failure(): void
    {
        $exception = new AuthenticationExpiredException('Bing Ads');

        $this->mockTransport
            ->shouldReceive('getAccount')
            ->andThrow($exception);

        $this->expectException(AuthenticationExpiredException::class);
        $this->expectExceptionMessage('Authentication failed');

        $this->client->verifyConnectivity();
    }

    #[Test]
    public function it_propagates_external_service_unavailable_on_connectivity_failure(): void
    {
        $exception = new ExternalServiceUnavailableException('Bing Ads', 60);

        $this->mockTransport
            ->shouldReceive('getAccount')
            ->andThrow($exception);

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage('External service unavailable');

        $this->client->verifyConnectivity();
    }

    /*
    |--------------------------------------------------------------------------
    | getCampaignMetricsByDateRange Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_empty_array_when_transport_returns_null(): void
    {
        $dateRange = DateRange::singleDay(new DateTimeImmutable('2024-05-10'));

        $this->mockTransport
            ->shouldReceive('getCampaignPerformanceReportCsv')
            ->once()
            ->with(Mockery::on(static fn(DateRange $range): bool => $range->from->format('Y-m-d') === '2024-05-10'
                    && $range->to->format('Y-m-d') === '2024-05-10'))
            ->andReturn(null);

        $result = $this->client->getCampaignMetricsByDateRange($dateRange);

        $this->assertSame([], $result);
    }

    #[Test]
    public function it_returns_campaign_metrics_for_valid_csv(): void
    {
        $dateRange = DateRange::singleDay(new DateTimeImmutable('2024-05-10'));

        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
123,Test Campaign,2024-05-10,50.25,100,5000,10.5
CSV;

        $this->mockTransport
            ->shouldReceive('getCampaignPerformanceReportCsv')
            ->once()
            ->andReturn($csv);

        $result = $this->client->getCampaignMetricsByDateRange($dateRange);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(CampaignMetrics::class, $result[0]);
        $this->assertSame(123, $result[0]->campaignId);
        $this->assertSame('Test Campaign', $result[0]->campaignName);
        $this->assertSame(50.25, $result[0]->costInPounds);
        $this->assertSame(100, $result[0]->clicks);
        $this->assertSame(5000, $result[0]->impressions);
        $this->assertSame(10.5, $result[0]->conversions);
        $this->assertSame('2024-05-10', $result[0]->date);
    }

    #[Test]
    public function it_returns_multiple_campaign_metrics(): void
    {
        $dateRange = DateRange::singleDay(new DateTimeImmutable('2024-05-10'));

        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
111,Campaign A,2024-05-10,10.00,50,2500,5.0
222,Campaign B,2024-05-10,20.00,100,5000,10.0
333,Campaign C,2024-05-10,30.00,150,7500,15.0
CSV;

        $this->mockTransport
            ->shouldReceive('getCampaignPerformanceReportCsv')
            ->once()
            ->andReturn($csv);

        $result = $this->client->getCampaignMetricsByDateRange($dateRange);

        $this->assertCount(3, $result);
        $this->assertSame(111, $result[0]->campaignId);
        $this->assertSame(222, $result[1]->campaignId);
        $this->assertSame(333, $result[2]->campaignId);
    }

    #[Test]
    public function it_propagates_external_service_unavailable_from_metrics_fetch(): void
    {
        $exception = new ExternalServiceUnavailableException('Bing Ads', 120);
        $dateRange = DateRange::singleDay(new DateTimeImmutable('2024-05-10'));

        $this->mockTransport
            ->shouldReceive('getCampaignPerformanceReportCsv')
            ->andThrow($exception);

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->getCampaignMetricsByDateRange($dateRange);
    }

    #[Test]
    public function it_propagates_authentication_expired_from_metrics_fetch(): void
    {
        $exception = new AuthenticationExpiredException('Bing Ads');
        $dateRange = DateRange::singleDay(new DateTimeImmutable('2024-05-10'));

        $this->mockTransport
            ->shouldReceive('getCampaignPerformanceReportCsv')
            ->andThrow($exception);

        $this->expectException(AuthenticationExpiredException::class);

        $this->client->getCampaignMetricsByDateRange($dateRange);
    }
}
