<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\AdSpend\Mixpanel;

use App\Domain\AdSpend\Exceptions\MixpanelApiException;
use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Infrastructure\AdSpend\Mixpanel\MixpanelLookupTableClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(MixpanelLookupTableClient::class)]
final class MixpanelLookupTableClientTest extends TestCase
{
    private MixpanelLookupTableClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new MixpanelLookupTableClient(
            mixpanelServiceUrl: 'https://api.mixpanel.com/api',
            mixpanelServiceToken: 'test-service-token',
            mixpanelWorkspaceId: 'test-workspace-id',
            lookupTableName: 'campaign_lookup',
        );
    }

    #[Test]
    public function it_replaces_lookup_table_with_valid_campaigns(): void
    {
        Http::fake([
            'https://api.mixpanel.com/api/lookuptables/test-workspace-id/campaign_lookup' => Http::response(
                status: 200,
                body: '{"status": "ok"}',
            ),
        ]);

        $campaigns = [
            new Campaign(
                campaignId: 123456789,
                campaignName: '[01] Search - Branded',
                status: 'ENABLED',
            ),
            new Campaign(
                campaignId: 987654321,
                campaignName: '[02] Performance Max',
                status: 'PAUSED',
            ),
        ];

        $this->client->replaceCampaignLookupTable($campaigns);

        Http::assertSent(static fn($request) => $request->method() === 'PUT'
                && $request->url() === 'https://api.mixpanel.com/api/lookuptables/test-workspace-id/campaign_lookup');
    }

    #[Test]
    public function it_throws_on_api_error_response(): void
    {
        Http::fake([
            'https://api.mixpanel.com/api/lookuptables/test-workspace-id/campaign_lookup' => Http::response(
                status: 400,
                body: '{"error": "Invalid request"}',
            ),
        ]);

        $campaigns = [
            new Campaign(campaignId: 123, campaignName: 'Test', status: 'ENABLED'),
        ];

        $this->expectException(MixpanelApiException::class);

        $this->client->replaceCampaignLookupTable($campaigns);
    }

    #[Test]
    public function it_throws_on_server_error_response(): void
    {
        Http::fake([
            'https://api.mixpanel.com/api/lookuptables/test-workspace-id/campaign_lookup' => Http::response(
                status: 500,
                body: 'Internal Server Error',
            ),
        ]);

        $campaigns = [
            new Campaign(campaignId: 123, campaignName: 'Test', status: 'ENABLED'),
        ];

        $this->expectException(MixpanelApiException::class);

        $this->client->replaceCampaignLookupTable($campaigns);
    }

    #[Test]
    public function it_handles_empty_campaign_list(): void
    {
        Http::fake([
            'https://api.mixpanel.com/api/lookuptables/test-workspace-id/campaign_lookup' => Http::response(status: 200),
        ]);

        $this->client->replaceCampaignLookupTable([]);

        Http::assertSent(static fn($request) => $request->method() === 'PUT');
    }

    #[Test]
    public function it_includes_all_campaign_statuses(): void
    {
        Http::fake([
            'https://api.mixpanel.com/api/lookuptables/test-workspace-id/campaign_lookup' => Http::response(status: 200),
        ]);

        $campaigns = [
            new Campaign(campaignId: 111, campaignName: 'Enabled', status: 'ENABLED'),
            new Campaign(campaignId: 222, campaignName: 'Paused', status: 'PAUSED'),
            new Campaign(campaignId: 333, campaignName: 'Removed', status: 'REMOVED'),
            new Campaign(campaignId: 444, campaignName: 'Unspecified', status: 'UNSPECIFIED'),
        ];

        $this->client->replaceCampaignLookupTable($campaigns);

        Http::assertSent(static fn($request) => $request->method() === 'PUT');
    }
}
