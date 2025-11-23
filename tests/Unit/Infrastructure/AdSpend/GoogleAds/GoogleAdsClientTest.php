<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\AdSpend\GoogleAds;

use App\Domain\AdSpend\Exceptions\ApiRateLimitException;
use App\Domain\AdSpend\Exceptions\GoogleAdsApiException;
use App\Domain\AdSpend\Exceptions\InvalidGoogleAdsResponseException;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Infrastructure\GoogleAds\GoogleAdsClient;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient as SdkGoogleAdsClient;
use Google\Ads\GoogleAds\V22\Common\Metrics;
use Google\Ads\GoogleAds\V22\Common\Segments;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use Google\ApiCore\ApiException;
use Google\ApiCore\PagedListResponse;
use Google\Rpc\Code;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionProperty;
use Tests\TestCase;

#[CoversClass(GoogleAdsClient::class)]
final class GoogleAdsClientTest extends TestCase
{
    private GoogleAdsClient $client;

    private MockObject $sdkClient;

    private MockObject $serviceClientMock;

    private const string CUSTOMER_ID = '1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock service client with dynamic search method
        $this->serviceClientMock = $this->getMockBuilder(GoogleAdsServiceClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['search'])
            ->getMock();

        // Create mock SDK client that returns our service client mock
        $this->sdkClient = $this->createMock(SdkGoogleAdsClient::class);
        // Use disableOriginalConstructor to bypass type checking
        $this->sdkClient->method('getGoogleAdsServiceClient')
            ->willReturnCallback(fn() => $this->serviceClientMock);

        // Create real GoogleAdsClient with mocked dependencies
        $this->client = new GoogleAdsClient(
            sdkClient: $this->sdkClient,
            customerId: self::CUSTOMER_ID,
        );
    }

    /**
     * Helper to configure service client mock with response
     */
    private function mockSearchResponse(mixed $response): void
    {
        $this->serviceClientMock->method('search')->willReturn($response);
    }

    /**
     * Helper to configure service client mock with callback
     */
    private function mockSearchCallback(callable $callback): void
    {
        $this->serviceClientMock->method('search')->willReturnCallback($callback);
    }

    /**
     * Helper to configure service client mock to throw exception
     */
    private function mockSearchThrow(Throwable $exception): void
    {
        $this->serviceClientMock->method('search')->willThrowException($exception);
    }

    #[Test]
    public function it_returns_campaign_metrics_for_single_result(): void
    {
        $row = $this->createMockGoogleAdsRow(
            campaignId: 123,
            campaignName: 'Test Campaign',
            costMicros: 50_250_000,
            clicks: 150,
            impressions: 7500,
            conversions: 10.5,
            date: '2024-05-10',
        );

        $response = $this->getMockBuilder(PagedListResponse::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['iterateAllElements'])
            ->getMock();
        $response->method('iterateAllElements')->willReturn([$row]);

        $this->mockSearchResponse($response);

        $result = $this->client->getDailyCampaignMetrics('2024-05-10');

        self::assertCount(1, $result);
        self::assertInstanceOf(CampaignMetrics::class, $result[0]);
        self::assertSame(123, $result[0]->campaignId);
        self::assertSame('Test Campaign', $result[0]->campaignName);
        self::assertSame(50.25, $result[0]->costInPounds);
    }

    #[Test]
    public function it_returns_campaign_metrics_for_multiple_results(): void
    {
        $row1 = $this->createMockGoogleAdsRow(
            campaignId: 111,
            campaignName: 'Campaign 1',
            costMicros: 10_000_000,
            clicks: 50,
            impressions: 2500,
            conversions: 5.0,
            date: '2024-05-10',
        );
        $row2 = $this->createMockGoogleAdsRow(
            campaignId: 222,
            campaignName: 'Campaign 2',
            costMicros: 20_000_000,
            clicks: 100,
            impressions: 5000,
            conversions: 10.0,
            date: '2024-05-10',
        );
        $row3 = $this->createMockGoogleAdsRow(
            campaignId: 333,
            campaignName: 'Campaign 3',
            costMicros: 30_000_000,
            clicks: 150,
            impressions: 7500,
            conversions: 15.0,
            date: '2024-05-10',
        );

        $response = $this->getMockBuilder(PagedListResponse::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['iterateAllElements'])
            ->getMock();
        $response->method('iterateAllElements')->willReturn([$row1, $row2, $row3]);

        $this->mockSearchResponse($response);

        $result = $this->client->getDailyCampaignMetrics('2024-05-10');

        self::assertCount(3, $result);
        self::assertSame(111, $result[0]->campaignId);
        self::assertSame(222, $result[1]->campaignId);
        self::assertSame(333, $result[2]->campaignId);
    }

    #[Test]
    public function it_returns_empty_array_when_no_results(): void
    {
        $response = $this->getMockBuilder(PagedListResponse::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['iterateAllElements'])
            ->getMock();
        $response->method('iterateAllElements')->willReturn([]);

        $this->mockSearchResponse($response);

        $result = $this->client->getDailyCampaignMetrics('2024-05-10');

        self::assertIsArray($result);
        self::assertCount(0, $result);
    }

    #[Test]
    public function it_constructs_gaql_query_with_correct_date(): void
    {
        $response = $this->getMockBuilder(PagedListResponse::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['iterateAllElements'])
            ->getMock();
        $response->method('iterateAllElements')->willReturn([]);

        $this->mockSearchCallback(static function ($request) use ($response) {
            $query = $request->getQuery();
            self::assertStringContainsString("WHERE segments.date = '2024-05-10'", $query);

            return $response;
        });

        $this->client->getDailyCampaignMetrics('2024-05-10');
    }

    #[Test]
    public function it_sets_customer_id_on_request(): void
    {
        $response = $this->getMockBuilder(PagedListResponse::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['iterateAllElements'])
            ->getMock();
        $response->method('iterateAllElements')->willReturn([]);

        $this->mockSearchCallback(static function ($request) use ($response) {
            self::assertSame(self::CUSTOMER_ID, $request->getCustomerId());

            return $response;
        });

        $this->client->getDailyCampaignMetrics('2024-05-10');
    }

    #[Test]
    public function it_sets_page_size_to_10000(): void
    {
        $response = $this->getMockBuilder(PagedListResponse::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['iterateAllElements'])
            ->getMock();
        $response->method('iterateAllElements')->willReturn([]);

        $this->mockSearchCallback(static function ($request) use ($response) {
            self::assertSame(10000, $request->getPageSize());

            return $response;
        });

        $this->client->getDailyCampaignMetrics('2024-05-10');
    }

    #[Test]
    public function it_throws_api_rate_limit_exception_on_resource_exhausted(): void
    {
        $apiException = new ApiException(
            'Rate limit exceeded',
            Code::RESOURCE_EXHAUSTED,
            'RESOURCE_EXHAUSTED',
        );

        $this->serviceClientMock->method('search')->willThrowException($apiException);

        $this->expectException(ApiRateLimitException::class);
        $this->expectExceptionMessage('Google Ads API rate limit exceeded');

        $this->client->getDailyCampaignMetrics('2024-05-10');
    }

    #[Test]
    public function it_extracts_retry_after_from_exception_metadata(): void
    {
        $apiException = new ApiException(
            'Rate limit exceeded',
            Code::RESOURCE_EXHAUSTED,
            'RESOURCE_EXHAUSTED',
        );
        // Mock metadata access via reflection since ApiException doesn't accept metadata param
        $reflectionProperty = new ReflectionProperty($apiException, 'metadata');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($apiException, ['retry-after' => '180']);

        $this->serviceClientMock->method('search')->willThrowException($apiException);

        try {
            $this->client->getDailyCampaignMetrics('2024-05-10');
        } catch (ApiRateLimitException $e) {
            self::assertSame(180, $e->getRetryAfter());

            return;
        }

        self::fail('Expected ApiRateLimitException to be thrown');
    }

    #[Test]
    public function it_defaults_retry_after_to_60_when_metadata_missing(): void
    {
        $apiException = new ApiException(
            'Rate limit exceeded',
            Code::RESOURCE_EXHAUSTED,
            'RESOURCE_EXHAUSTED',
        );

        $this->serviceClientMock->method('search')->willThrowException($apiException);

        try {
            $this->client->getDailyCampaignMetrics('2024-05-10');
        } catch (ApiRateLimitException $e) {
            self::assertSame(60, $e->getRetryAfter());

            return;
        }

        self::fail('Expected ApiRateLimitException to be thrown');
    }

    #[Test]
    public function it_preserves_original_exception_in_rate_limit(): void
    {
        $apiException = new ApiException(
            'Rate limit exceeded',
            Code::RESOURCE_EXHAUSTED,
            'RESOURCE_EXHAUSTED',
        );

        $this->serviceClientMock->method('search')->willThrowException($apiException);

        try {
            $this->client->getDailyCampaignMetrics('2024-05-10');
        } catch (ApiRateLimitException $e) {
            self::assertNotNull($e->getPrevious());
            self::assertInstanceOf(ApiException::class, $e->getPrevious());

            return;
        }

        self::fail('Expected ApiRateLimitException to be thrown');
    }

    #[Test]
    public function it_throws_google_ads_api_exception_on_other_codes(): void
    {
        $apiException = new ApiException(
            'Invalid customer ID',
            Code::INVALID_ARGUMENT,
            'INVALID_ARGUMENT',
        );

        $this->serviceClientMock->method('search')->willThrowException($apiException);

        $this->expectException(GoogleAdsApiException::class);

        $this->client->getDailyCampaignMetrics('2024-05-10');
    }

    #[Test]
    public function it_preserves_exception_code_in_api_exception(): void
    {
        $apiException = new ApiException(
            'Internal server error',
            Code::INTERNAL,
            'INTERNAL',
        );

        $this->serviceClientMock->method('search')->willThrowException($apiException);

        try {
            $this->client->getDailyCampaignMetrics('2024-05-10');
        } catch (GoogleAdsApiException $e) {
            // Verify the error code is in the exception message
            self::assertStringContainsString('13', $e->getMessage());

            return;
        }

        self::fail('Expected GoogleAdsApiException to be thrown');
    }

    #[Test]
    public function it_passes_through_invalid_response_exception(): void
    {
        $row = $this->createMock(GoogleAdsRow::class);
        $row->method('getCampaign')->willReturn(null);

        $response = $this->getMockBuilder(PagedListResponse::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['iterateAllElements'])
            ->getMock();
        $response->method('iterateAllElements')->willReturn([$row]);

        $this->mockSearchResponse($response);

        $this->expectException(InvalidGoogleAdsResponseException::class);

        $this->client->getDailyCampaignMetrics('2024-05-10');
    }

    private function createMockGoogleAdsRow(
        int $campaignId,
        string $campaignName,
        int $costMicros,
        int $clicks,
        int $impressions,
        float $conversions,
        string $date,
    ): GoogleAdsRow {
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn($campaignId);
        $campaign->method('getName')->willReturn($campaignName);

        $metrics = $this->createMock(Metrics::class);
        $metrics->method('getCostMicros')->willReturn($costMicros);
        $metrics->method('getClicks')->willReturn($clicks);
        $metrics->method('getImpressions')->willReturn($impressions);
        $metrics->method('getConversions')->willReturn($conversions);

        $segments = $this->createMock(Segments::class);
        $segments->method('getDate')->willReturn($date);

        $row = $this->createMock(GoogleAdsRow::class);
        $row->method('getCampaign')->willReturn($campaign);
        $row->method('getMetrics')->willReturn($metrics);
        $row->method('getSegments')->willReturn($segments);

        return $row;
    }
}
