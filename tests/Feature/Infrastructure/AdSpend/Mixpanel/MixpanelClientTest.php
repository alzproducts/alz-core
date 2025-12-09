<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\AdSpend\Mixpanel;

use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Domain\Exceptions\PayloadSerializationException;
use App\Infrastructure\Mixpanel\MixpanelClient;
use App\Infrastructure\Mixpanel\MixpanelConfig;
use App\Infrastructure\Mixpanel\MixpanelHttpTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(MixpanelClient::class)]
final class MixpanelClientTest extends TestCase
{
    private MixpanelClient $client;

    private const string BASE_URL = 'https://api-eu.mixpanel.com';

    private const string PROJECT_ID = 'test-project-123';

    private const string USERNAME = 'test-username';

    private const string PASSWORD = 'test-password';

    private const string LOOKUP_TABLE_ID = 'test-lookup-table-id';

    /** @var array<string, string> */
    private const array LOOKUP_TABLE_IDS = ['utm_campaigns' => self::LOOKUP_TABLE_ID];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $config = new MixpanelConfig(
            dataApiBaseUrl: self::BASE_URL,
            serviceAccountUsername: self::USERNAME,
            serviceAccountPassword: self::PASSWORD,
            projectId: self::PROJECT_ID,
            lookupTableIds: self::LOOKUP_TABLE_IDS,
        );

        $transport = new MixpanelHttpTransport($config);

        $this->client = new MixpanelClient($transport, $config);
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
    public function it_throws_external_service_unavailable_exception_on_429(): void
    {
        Http::fake(['*' => Http::response([], 429)]);

        $event = $this->createEvent();

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Mixpanel' is unavailable");

        $this->client->importCampaigns([$event]);
    }

    #[Test]
    public function it_preserves_original_exception_on_429(): void
    {
        Http::fake([
            '*' => Http::response([], 429, ['Retry-After' => '120']),
        ]);

        $event = $this->createEvent();

        try {
            $this->client->importCampaigns([$event]);
        } catch (ExternalServiceUnavailableException $e) {
            self::assertNotNull($e->getPrevious());
            self::assertInstanceOf(RequestException::class, $e->getPrevious());

            return;
        }

        self::fail('Expected ExternalServiceUnavailableException to be thrown');
    }

    #[Test]
    public function it_extracts_retry_after_from_response_header(): void
    {
        Http::fake([
            '*' => Http::response([], 429, ['Retry-After' => '180']),
        ]);

        $event = $this->createEvent();

        try {
            $this->client->importCampaigns([$event]);
        } catch (ExternalServiceUnavailableException $e) {
            self::assertSame(180, $e->retryAfter);

            return;
        }

        self::fail('Expected ExternalServiceUnavailableException to be thrown');
    }

    #[Test]
    public function it_returns_null_retry_after_when_header_is_missing(): void
    {
        Http::fake([
            '*' => Http::response([], 429), // No Retry-After header
        ]);

        $event = $this->createEvent();

        try {
            $this->client->importCampaigns([$event]);
        } catch (ExternalServiceUnavailableException $e) {
            self::assertNull($e->retryAfter); // Null when API doesn't specify

            return;
        }

        self::fail('Expected ExternalServiceUnavailableException to be thrown');
    }

    #[Test]
    public function it_returns_null_for_zero_retry_after_header(): void
    {
        Http::fake([
            '*' => Http::response([], 429, ['Retry-After' => '0']),
        ]);

        $event = $this->createEvent();

        try {
            $this->client->importCampaigns([$event]);
        } catch (ExternalServiceUnavailableException $e) {
            self::assertNull($e->retryAfter); // Zero is invalid per RFC 7231

            return;
        }

        self::fail('Expected ExternalServiceUnavailableException to be thrown');
    }

    #[Test]
    public function it_returns_null_for_negative_retry_after_header(): void
    {
        Http::fake([
            '*' => Http::response([], 429, ['Retry-After' => '-10']),
        ]);

        $event = $this->createEvent();

        try {
            $this->client->importCampaigns([$event]);
        } catch (ExternalServiceUnavailableException $e) {
            self::assertNull($e->retryAfter); // Negative is invalid per RFC 7231

            return;
        }

        self::fail('Expected ExternalServiceUnavailableException to be thrown');
    }

    #[Test]
    public function it_accepts_retry_after_value_of_one(): void
    {
        Http::fake([
            '*' => Http::response([], 429, ['Retry-After' => '1']),
        ]);

        $event = $this->createEvent();

        try {
            $this->client->importCampaigns([$event]);
        } catch (ExternalServiceUnavailableException $e) {
            self::assertSame(1, $e->retryAfter); // Boundary: exactly 1 is valid

            return;
        }

        self::fail('Expected ExternalServiceUnavailableException to be thrown');
    }

    #[Test]
    public function it_throws_invalid_api_request_exception_on_400_bad_request(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Invalid payload'], 400)]);

        $event = $this->createEvent();

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('Invalid payload');

        $this->client->importCampaigns([$event]);
    }

    #[Test]
    public function it_throws_authentication_expired_exception_on_401_unauthorized(): void
    {
        Http::fake(['*' => Http::response([], 401)]);

        $event = $this->createEvent();

        $this->expectException(AuthenticationExpiredException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $this->client->importCampaigns([$event]);
    }

    #[Test]
    public function it_throws_external_service_unavailable_on_5xx(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $event = $this->createEvent();

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->importCampaigns([$event]);
    }

    #[Test]
    public function it_preserves_exception_in_invalid_api_request(): void
    {
        Http::fake(['*' => Http::response([], 400)]);

        $event = $this->createEvent();

        try {
            $this->client->importCampaigns([$event]);
        } catch (InvalidApiRequestException $e) {
            self::assertNotNull($e->getPrevious());
            self::assertInstanceOf(RequestException::class, $e->getPrevious());

            return;
        }

        self::fail('Expected InvalidApiRequestException to be thrown');
    }

    // ========================================================================
    // Lookup Table Tests
    // ========================================================================

    #[Test]
    public function it_replaces_lookup_table_with_single_campaign(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $campaign = $this->createCampaign();

        $this->client->replaceCampaignLookupTable([$campaign]);

        Http::assertSent(static function (Request $request): bool {
            self::assertSame('PUT', $request->method());
            self::assertStringContainsString('/lookup-tables/', $request->url());

            return true;
        });
    }

    #[Test]
    public function it_replaces_lookup_table_with_multiple_campaigns(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $campaigns = [
            $this->createCampaign(campaignId: 111, campaignName: 'Campaign One', status: 'ENABLED'),
            $this->createCampaign(campaignId: 222, campaignName: 'Campaign Two', status: 'PAUSED'),
            $this->createCampaign(campaignId: 333, campaignName: 'Campaign Three', status: 'ENABLED'),
        ];

        $this->client->replaceCampaignLookupTable($campaigns);

        Http::assertSent(static function (Request $request): bool {
            self::assertSame('PUT', $request->method());

            return true;
        });
    }

    #[Test]
    public function it_posts_to_correct_lookup_table_endpoint(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $campaign = $this->createCampaign();
        $this->client->replaceCampaignLookupTable([$campaign]);

        Http::assertSent(static function (Request $request): bool {
            $expectedUrl = self::BASE_URL . '/lookup-tables/' . self::LOOKUP_TABLE_ID . '?project_id=' . self::PROJECT_ID;
            self::assertSame($expectedUrl, $request->url());

            return true;
        });
    }

    #[Test]
    public function it_uses_put_method_for_lookup_table(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $campaign = $this->createCampaign();
        $this->client->replaceCampaignLookupTable([$campaign]);

        Http::assertSent(static function (Request $request): bool {
            self::assertSame('PUT', $request->method());

            return true;
        });
    }

    #[Test]
    public function it_sends_csv_content_type_for_lookup_table(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $campaign = $this->createCampaign();
        $this->client->replaceCampaignLookupTable([$campaign]);

        Http::assertSent(static function (Request $request): bool {
            $contentTypeHeader = $request->header('Content-Type');
            self::assertIsArray($contentTypeHeader);
            self::assertStringContainsString('text/csv', $contentTypeHeader[0]);

            return true;
        });
    }

    #[Test]
    public function it_uses_http_basic_auth_for_lookup_table(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $campaign = $this->createCampaign();
        $this->client->replaceCampaignLookupTable([$campaign]);

        Http::assertSent(static function (Request $request): bool {
            $authHeader = $request->header('Authorization');
            self::assertIsArray($authHeader);
            self::assertStringStartsWith('Basic ', $authHeader[0]);

            return true;
        });
    }

    #[Test]
    public function it_formats_campaigns_as_csv_with_headers(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $campaign = $this->createCampaign(
            campaignId: 999,
            campaignName: 'Test Campaign Name',
            status: 'ENABLED',
        );

        $this->client->replaceCampaignLookupTable([$campaign]);

        Http::assertSent(static function (Request $request): bool {
            $body = $request->body();
            self::assertIsString($body);

            // Verify CSV headers
            self::assertStringContainsString('utm_campaign,campaign_name,campaign_status', $body);

            // Verify campaign data row
            self::assertStringContainsString('999,Test Campaign Name,ENABLED', $body);

            return true;
        });
    }

    #[Test]
    public function it_handles_csv_escaping_for_special_characters(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $campaign = $this->createCampaign(
            campaignId: 123,
            campaignName: 'Campaign with "quotes" and, commas',
            status: 'ENABLED',
        );

        $this->client->replaceCampaignLookupTable([$campaign]);

        Http::assertSent(static function (Request $request): bool {
            $body = $request->body();
            self::assertIsString($body);

            // RFC 4180: Fields containing commas or quotes must be quoted
            self::assertStringContainsString('"Campaign with ""quotes"" and, commas"', $body);

            return true;
        });
    }

    #[Test]
    public function it_throws_external_service_unavailable_on_lookup_table_429(): void
    {
        Http::fake(['*' => Http::response([], 429)]);

        $campaign = $this->createCampaign();

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Mixpanel' is unavailable");

        $this->client->replaceCampaignLookupTable([$campaign]);
    }

    #[Test]
    public function it_throws_invalid_api_request_exception_on_lookup_table_400(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Invalid CSV'], 400)]);

        $campaign = $this->createCampaign();

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('Invalid CSV');

        $this->client->replaceCampaignLookupTable([$campaign]);
    }

    #[Test]
    public function it_throws_external_service_unavailable_on_lookup_table_5xx(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $campaign = $this->createCampaign();

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->replaceCampaignLookupTable([$campaign]);
    }

    #[Test]
    public function it_preserves_exception_in_lookup_table_error(): void
    {
        Http::fake(['*' => Http::response([], 400)]);

        $campaign = $this->createCampaign();

        try {
            $this->client->replaceCampaignLookupTable([$campaign]);
        } catch (InvalidApiRequestException $e) {
            self::assertNotNull($e->getPrevious());
            self::assertInstanceOf(RequestException::class, $e->getPrevious());

            return;
        }

        self::fail('Expected InvalidApiRequestException to be thrown');
    }

    #[Test]
    public function it_formats_multiple_campaigns_as_csv_rows(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $campaigns = [
            $this->createCampaign(campaignId: 111, campaignName: 'Campaign One', status: 'ENABLED'),
            $this->createCampaign(campaignId: 222, campaignName: 'Campaign Two', status: 'PAUSED'),
            $this->createCampaign(campaignId: 333, campaignName: 'Campaign Three', status: 'REMOVED'),
        ];

        $this->client->replaceCampaignLookupTable($campaigns);

        Http::assertSent(static function (Request $request): bool {
            $body = $request->body();
            self::assertIsString($body);

            // Verify all three campaigns are present
            self::assertStringContainsString('111,Campaign One,ENABLED', $body);
            self::assertStringContainsString('222,Campaign Two,PAUSED', $body);
            self::assertStringContainsString('333,Campaign Three,REMOVED', $body);

            // Count lines: 1 header + 3 data rows = 4 total
            $lines = \explode("\n", \mb_trim($body));
            self::assertCount(4, $lines);

            return true;
        });
    }

    // ========================================================================
    // Verify Connectivity Tests
    // ========================================================================

    #[Test]
    public function it_verifies_connectivity_successfully(): void
    {
        Http::fake([
            'https://mixpanel.com/api/app/me' => Http::response(['user' => 'test'], 200),
        ]);

        // Should not throw any exception
        $this->client->verifyConnectivity();

        Http::assertSent(static function (Request $request): bool {
            self::assertSame('GET', $request->method());
            self::assertSame('https://mixpanel.com/api/app/me', $request->url());

            // Verify Basic Auth header is sent
            $authHeader = $request->header('Authorization');
            self::assertIsArray($authHeader);
            self::assertStringStartsWith('Basic ', $authHeader[0]);

            return true;
        });
    }

    #[Test]
    public function it_throws_authentication_expired_exception_on_connectivity_401(): void
    {
        Http::fake([
            'https://mixpanel.com/api/app/me' => Http::response('Unauthorized', 401),
        ]);

        $this->expectException(AuthenticationExpiredException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $this->client->verifyConnectivity();
    }

    #[Test]
    public function it_throws_external_service_unavailable_on_connectivity_500(): void
    {
        Http::fake([
            'https://mixpanel.com/api/app/me' => Http::response('Internal Server Error', 500),
        ]);

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Mixpanel' is unavailable");

        $this->client->verifyConnectivity();
    }

    #[Test]
    public function it_preserves_original_exception_on_connectivity_failure(): void
    {
        Http::fake([
            'https://mixpanel.com/api/app/me' => Http::response('Forbidden', 403),
        ]);

        try {
            $this->client->verifyConnectivity();
        } catch (AuthenticationExpiredException $e) {
            self::assertNotNull($e->getPrevious());
            self::assertInstanceOf(RequestException::class, $e->getPrevious());

            return;
        }

        self::fail('Expected AuthenticationExpiredException to be thrown');
    }

    // ========================================================================
    // Payload Serialization Tests
    // ========================================================================

    #[Test]
    public function it_throws_payload_serialization_exception_when_json_encoding_fails(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        // INF passes domain validation (INF >= 0 is true) but fails json_encode
        // This tests the encodeJson() exception translation path
        $event = $this->createEvent(cost: \INF);

        $this->expectException(PayloadSerializationException::class);
        $this->expectExceptionMessage('Mixpanel');

        $this->client->importCampaigns([$event]);
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

    private function createCampaign(
        int $campaignId = 123,
        string $campaignName = 'Test Campaign',
        string $status = 'ENABLED',
    ): Campaign {
        return new Campaign(
            id: $campaignId,
            name: $campaignName,
            status: $status,
        );
    }
}
