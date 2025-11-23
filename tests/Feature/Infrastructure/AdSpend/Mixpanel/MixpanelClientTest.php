<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\AdSpend\Mixpanel;

use App\Domain\AdSpend\Exceptions\ApiRateLimitException;
use App\Domain\AdSpend\Exceptions\MixpanelApiException;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Infrastructure\AdSpend\Mixpanel\MixpanelClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(MixpanelClient::class)]
final class MixpanelClientTest extends TestCase
{
    private MixpanelClient $client;

    private const string BASE_URL = 'https://api.mixpanel.com';

    private const string PROJECT_ID = 'test-project-123';

    private const string USERNAME = 'test-username';

    private const string PASSWORD = 'test-password';

    private const string LOOKUP_TABLE_ID = 'test-lookup-table-id';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new MixpanelClient(
            mixpanelBaseUrl: self::BASE_URL,
            serviceAccountUsername: self::USERNAME,
            serviceAccountPassword: self::PASSWORD,
            projectId: self::PROJECT_ID,
            lookupTableId: self::LOOKUP_TABLE_ID,
        );
    }

    #[Test]
    public function it_imports_single_event_successfully(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $event = $this->createEvent();

        $this->client->importCampaigns([$event]);

        Http::assertSent(static function (Request $request): bool {
            self::assertSame('POST', $request->method());
            self::assertStringContainsString('/import', $request->url());

            return true;
        });
    }

    #[Test]
    public function it_imports_multiple_events_successfully(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $events = [
            $this->createEvent(campaignId: 111),
            $this->createEvent(campaignId: 222),
            $this->createEvent(campaignId: 333),
        ];

        $this->client->importCampaigns($events);

        Http::assertSent(static function (Request $request): bool {
            self::assertSame('POST', $request->method());
            $payload = $request->data();
            self::assertCount(3, $payload);

            return true;
        });
    }

    #[Test]
    public function it_returns_early_for_empty_array(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->client->importCampaigns([]);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_posts_to_correct_endpoint(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $event = $this->createEvent();
        $this->client->importCampaigns([$event]);

        Http::assertSent(static function (Request $request): bool {
            $expectedUrl = self::BASE_URL . '/import?project_id=' . self::PROJECT_ID;
            self::assertSame($expectedUrl, $request->url());

            return true;
        });
    }

    #[Test]
    public function it_uses_post_method(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $event = $this->createEvent();
        $this->client->importCampaigns([$event]);

        Http::assertSent(static function (Request $request): bool {
            self::assertSame('POST', $request->method());

            return true;
        });
    }

    #[Test]
    public function it_sends_json_content_type(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $event = $this->createEvent();
        $this->client->importCampaigns([$event]);

        Http::assertSent(static function (Request $request): bool {
            $contentTypeHeader = $request->header('Content-Type');
            self::assertIsArray($contentTypeHeader);
            self::assertStringContainsString('application/json', $contentTypeHeader[0]);

            return true;
        });
    }

    #[Test]
    public function it_uses_http_basic_auth(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $event = $this->createEvent();
        $this->client->importCampaigns([$event]);

        Http::assertSent(static function (Request $request): bool {
            $authHeader = $request->header('Authorization');
            self::assertIsArray($authHeader);
            self::assertStringStartsWith('Basic ', $authHeader[0]);

            return true;
        });
    }

    #[Test]
    public function it_sends_events_as_json_array(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $event = $this->createEvent();
        $this->client->importCampaigns([$event]);

        Http::assertSent(static function (Request $request): bool {
            $payload = $request->data();
            self::assertIsArray($payload);
            self::assertCount(1, $payload);

            return true;
        });
    }

    #[Test]
    public function it_converts_events_to_mixpanel_format(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $event = $this->createEvent(
            campaignId: 999,
            campaignName: 'Test Campaign Name',
            date: '2024-11-18',
            cost: 99.99,
            clicks: 50,
            impressions: 2500,
            conversions: 2.5,
        );

        $this->client->importCampaigns([$event]);

        Http::assertSent(static function (Request $request): bool {
            $payload = $request->data();
            $eventData = $payload[0];

            self::assertSame('Ad Data', $eventData['event']);
            self::assertSame(\strtotime('2024-11-18'), $eventData['properties']['time']);
            self::assertSame('G-2024-11-18-999', $eventData['properties']['$insert_id']);
            self::assertSame('Google', $eventData['properties']['source']);
            self::assertSame(999, $eventData['properties']['campaign_id']);
            self::assertSame('Test Campaign Name', $eventData['properties']['campaign_name']);
            self::assertSame(99.99, $eventData['properties']['cost']);
            self::assertSame(50, $eventData['properties']['clicks']);
            self::assertSame(2500, $eventData['properties']['impressions']);
            self::assertSame(2.5, $eventData['properties']['conversions']);
            self::assertSame('google', $eventData['properties']['utm_source']);
            self::assertSame('cpc', $eventData['properties']['utm_medium']);
            self::assertSame('Test Campaign Name', $eventData['properties']['utm_campaign']);

            return true;
        });
    }

    #[Test]
    public function it_throws_api_rate_limit_exception_on_429(): void
    {
        Http::fake(['*' => Http::response([], 429)]);

        $event = $this->createEvent();

        $this->expectException(ApiRateLimitException::class);
        $this->expectExceptionMessage('Mixpanel API rate limit exceeded after retries');

        $this->client->importCampaigns([$event]);
    }

    #[Test]
    public function it_extracts_numeric_retry_after_from_header(): void
    {
        Http::fake([
            '*' => Http::response([], 429, ['Retry-After' => '120']),
        ]);

        $event = $this->createEvent();

        try {
            $this->client->importCampaigns([$event]);
        } catch (ApiRateLimitException $e) {
            self::assertSame(120, $e->getRetryAfter());

            return;
        }

        self::fail('Expected ApiRateLimitException to be thrown');
    }

    #[Test]
    public function it_defaults_retry_after_to_60_when_header_missing(): void
    {
        Http::fake(['*' => Http::response([], 429)]);

        $event = $this->createEvent();

        try {
            $this->client->importCampaigns([$event]);
        } catch (ApiRateLimitException $e) {
            self::assertSame(60, $e->getRetryAfter());

            return;
        }

        self::fail('Expected ApiRateLimitException to be thrown');
    }

    #[Test]
    public function it_defaults_retry_after_to_60_when_non_numeric(): void
    {
        Http::fake([
            '*' => Http::response([], 429, ['Retry-After' => 'invalid-value']),
        ]);

        $event = $this->createEvent();

        try {
            $this->client->importCampaigns([$event]);
        } catch (ApiRateLimitException $e) {
            self::assertSame(60, $e->getRetryAfter());

            return;
        }

        self::fail('Expected ApiRateLimitException to be thrown');
    }

    #[Test]
    public function it_preserves_original_exception_in_rate_limit(): void
    {
        Http::fake(['*' => Http::response([], 429)]);

        $event = $this->createEvent();

        try {
            $this->client->importCampaigns([$event]);
        } catch (ApiRateLimitException $e) {
            self::assertNotNull($e->getPrevious());
            self::assertInstanceOf(RequestException::class, $e->getPrevious());

            return;
        }

        self::fail('Expected ApiRateLimitException to be thrown');
    }

    #[Test]
    public function it_throws_mixpanel_api_exception_on_400_bad_request(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Invalid payload'], 400)]);

        $event = $this->createEvent();

        $this->expectException(MixpanelApiException::class);

        $this->client->importCampaigns([$event]);
    }

    #[Test]
    public function it_throws_mixpanel_api_exception_on_401_unauthorized(): void
    {
        Http::fake(['*' => Http::response([], 401)]);

        $event = $this->createEvent();

        $this->expectException(MixpanelApiException::class);

        $this->client->importCampaigns([$event]);
    }

    #[Test]
    public function it_throws_mixpanel_api_exception_on_5xx(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $event = $this->createEvent();

        $this->expectException(MixpanelApiException::class);

        $this->client->importCampaigns([$event]);
    }

    #[Test]
    public function it_preserves_exception_message_in_api_exception(): void
    {
        Http::fake(['*' => Http::response([], 400)]);

        $event = $this->createEvent();

        try {
            $this->client->importCampaigns([$event]);
        } catch (MixpanelApiException $e) {
            self::assertNotNull($e->getPrevious());
            self::assertInstanceOf(RequestException::class, $e->getPrevious());

            return;
        }

        self::fail('Expected MixpanelApiException to be thrown');
    }

    private function createEvent(
        int $campaignId = 123,
        string $campaignName = 'Test Campaign',
        string $date = '2024-11-18',
        float $cost = 50.25,
        int $clicks = 100,
        int $impressions = 5000,
        float $conversions = 5.5,
    ): CampaignMetrics {
        return new CampaignMetrics(
            campaignId: $campaignId,
            campaignName: $campaignName,
            date: $date,
            costInPounds: $cost,
            clicks: $clicks,
            impressions: $impressions,
            conversions: $conversions,
        );
    }
}
