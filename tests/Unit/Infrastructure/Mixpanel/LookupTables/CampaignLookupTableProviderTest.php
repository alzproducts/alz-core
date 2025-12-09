<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Mixpanel\LookupTables;

use App\Application\Contracts\AdSpendClientInterface;
use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\Mixpanel\LookupTables\CampaignLookupTableProvider;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CampaignLookupTableProvider::class)]
final class CampaignLookupTableProviderTest extends TestCase
{
    private AdSpendClientInterface&MockInterface $adClient;

    private CampaignLookupTableProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adClient = Mockery::mock(AdSpendClientInterface::class);
        $this->provider = new CampaignLookupTableProvider($this->adClient);
    }

    // ========================================================================
    // Metadata Tests
    // ========================================================================

    #[Test]
    public function it_returns_correct_table_key(): void
    {
        self::assertSame('utm_campaigns', $this->provider->getTableKey());
    }

    #[Test]
    public function it_returns_correct_source_name(): void
    {
        self::assertSame('Google Ads', $this->provider->getSourceName());
    }

    #[Test]
    public function it_returns_correct_headers(): void
    {
        self::assertSame(
            ['utm_campaign', 'campaign_name', 'campaign_status'],
            $this->provider->getHeaders(),
        );
    }

    // ========================================================================
    // Fetch Rows Tests
    // ========================================================================

    #[Test]
    public function it_transforms_single_campaign_to_row(): void
    {
        $campaign = new Campaign(
            id: 123456789,
            name: '[01] Search - Branded',
            status: 'ENABLED',
        );

        $this->adClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([$campaign]);

        $rows = $this->provider->fetchRows();

        self::assertCount(1, $rows);
        self::assertSame(['123456789', '[01] Search - Branded', 'ENABLED'], $rows[0]);
    }

    #[Test]
    public function it_transforms_multiple_campaigns_to_rows(): void
    {
        $campaigns = [
            new Campaign(id: 111, name: 'Campaign One', status: 'ENABLED'),
            new Campaign(id: 222, name: 'Campaign Two', status: 'PAUSED'),
            new Campaign(id: 333, name: 'Campaign Three', status: 'REMOVED'),
        ];

        $this->adClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn($campaigns);

        $rows = $this->provider->fetchRows();

        self::assertCount(3, $rows);
        self::assertSame(['111', 'Campaign One', 'ENABLED'], $rows[0]);
        self::assertSame(['222', 'Campaign Two', 'PAUSED'], $rows[1]);
        self::assertSame(['333', 'Campaign Three', 'REMOVED'], $rows[2]);
    }

    #[Test]
    public function it_preserves_campaign_order(): void
    {
        $campaigns = [
            new Campaign(id: 999, name: 'Last', status: 'ENABLED'),
            new Campaign(id: 111, name: 'First', status: 'ENABLED'),
            new Campaign(id: 555, name: 'Middle', status: 'ENABLED'),
        ];

        $this->adClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn($campaigns);

        $rows = $this->provider->fetchRows();

        self::assertSame('999', $rows[0][0]);
        self::assertSame('111', $rows[1][0]);
        self::assertSame('555', $rows[2][0]);
    }

    #[Test]
    public function it_converts_campaign_id_to_string(): void
    {
        $campaign = new Campaign(
            id: 987654321,
            name: 'Test Campaign',
            status: 'ENABLED',
        );

        $this->adClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([$campaign]);

        $rows = $this->provider->fetchRows();

        self::assertIsString($rows[0][0]);
        self::assertSame('987654321', $rows[0][0]);
    }

    #[Test]
    public function it_returns_empty_array_when_no_campaigns(): void
    {
        $this->adClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([]);

        $rows = $this->provider->fetchRows();

        self::assertSame([], $rows);
    }

    #[Test]
    public function it_handles_all_campaign_status_values(): void
    {
        $campaigns = [
            new Campaign(id: 1, name: 'Enabled', status: 'ENABLED'),
            new Campaign(id: 2, name: 'Paused', status: 'PAUSED'),
            new Campaign(id: 3, name: 'Removed', status: 'REMOVED'),
            new Campaign(id: 4, name: 'Unspecified', status: 'UNSPECIFIED'),
            new Campaign(id: 5, name: 'Unknown', status: 'UNKNOWN'),
        ];

        $this->adClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn($campaigns);

        $rows = $this->provider->fetchRows();

        self::assertSame('ENABLED', $rows[0][2]);
        self::assertSame('PAUSED', $rows[1][2]);
        self::assertSame('REMOVED', $rows[2][2]);
        self::assertSame('UNSPECIFIED', $rows[3][2]);
        self::assertSame('UNKNOWN', $rows[4][2]);
    }

    #[Test]
    public function it_handles_campaign_with_special_characters(): void
    {
        $specialName = '[01] Search - Branded | Q4 2024 & Premium';
        $campaign = new Campaign(
            id: 123,
            name: $specialName,
            status: 'ENABLED',
        );

        $this->adClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([$campaign]);

        $rows = $this->provider->fetchRows();

        self::assertSame($specialName, $rows[0][1]);
    }

    // ========================================================================
    // Exception Propagation Tests
    // ========================================================================

    #[Test]
    public function it_propagates_external_service_exception(): void
    {
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $this->adClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andThrow($exception);

        $this->expectExceptionObject($exception);

        $this->provider->fetchRows();
    }
}
