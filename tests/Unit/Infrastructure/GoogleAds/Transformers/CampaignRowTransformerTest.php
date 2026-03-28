<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\GoogleAds\Transformers;

use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Infrastructure\GoogleAds\Exceptions\InvalidGoogleAdsResponseException;
use App\Infrastructure\GoogleAds\Transformers\CampaignRowTransformer;
use Google\Ads\GoogleAds\V22\Resources\Campaign as GoogleAdsCampaign;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CampaignRowTransformer::class)]
final class CampaignRowTransformerTest extends TestCase
{
    #[Test]
    public function it_transforms_row_with_enabled_status(): void
    {
        $row = $this->createRealRow(
            campaignId: 123456789,
            campaignName: '[01] Search - Branded',
            status: 2, // SDK: ENABLED = 2
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame(123456789, $campaign->id);
        $this->assertSame('[01] Search - Branded', $campaign->name);
        $this->assertSame('ENABLED', $campaign->status);
    }

    #[Test]
    public function it_transforms_row_with_paused_status(): void
    {
        $row = $this->createRealRow(
            campaignId: 987654321,
            campaignName: '[02] Performance Max',
            status: 3, // SDK: PAUSED = 3
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame(987654321, $campaign->id);
        $this->assertSame('[02] Performance Max', $campaign->name);
        $this->assertSame('PAUSED', $campaign->status);
    }

    #[Test]
    public function it_transforms_row_with_removed_status(): void
    {
        $row = $this->createRealRow(
            campaignId: 555555555,
            campaignName: 'Old Campaign',
            status: 4, // SDK: REMOVED = 4
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame(555555555, $campaign->id);
        $this->assertSame('Old Campaign', $campaign->name);
        $this->assertSame('REMOVED', $campaign->status);
    }

    #[Test]
    public function it_transforms_row_with_unspecified_status(): void
    {
        $row = $this->createRealRow(
            campaignId: 111111111,
            campaignName: 'Unspecified Campaign',
            status: 0,
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame(111111111, $campaign->id);
        $this->assertSame('Unspecified Campaign', $campaign->name);
        $this->assertSame('UNSPECIFIED', $campaign->status);
    }

    #[Test]
    #[DataProvider('allValidStatusEnums')]
    public function it_maps_all_valid_status_enums(int $enumValue, string $expectedStatus): void
    {
        $row = $this->createRealRow(
            campaignId: 1000,
            campaignName: 'Test Campaign',
            status: $enumValue,
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame($expectedStatus, $campaign->status);
    }

    public static function allValidStatusEnums(): array
    {
        // SDK enum values: UNSPECIFIED=0, UNKNOWN=1, ENABLED=2, PAUSED=3, REMOVED=4
        return [
            'UNSPECIFIED (0)' => [0, 'UNSPECIFIED'],
            'UNKNOWN (1)' => [1, 'UNKNOWN'],
            'ENABLED (2)' => [2, 'ENABLED'],
            'PAUSED (3)' => [3, 'PAUSED'],
            'REMOVED (4)' => [4, 'REMOVED'],
        ];
    }

    #[Test]
    public function it_throws_when_campaign_is_null(): void
    {
        // Real GoogleAdsRow without setCampaign() - getCampaign() returns null by default
        $row = new GoogleAdsRow();

        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Invalid Google Ads API response');

        CampaignRowTransformer::toCampaign($row);
    }

    #[Test]
    public function it_throws_on_invalid_status_enum_value(): void
    {
        $row = $this->createRealRow(
            campaignId: 123,
            campaignName: 'Test',
            status: 99,
        );

        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Invalid Google Ads API response');

        CampaignRowTransformer::toCampaign($row);
    }

    #[Test]
    public function it_throws_on_negative_status_enum_value(): void
    {
        $row = $this->createRealRow(
            campaignId: 123,
            campaignName: 'Test',
            status: -1,
        );

        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Invalid Google Ads API response');

        CampaignRowTransformer::toCampaign($row);
    }

    #[Test]
    public function it_casts_campaign_id_to_int(): void
    {
        $row = $this->createRealRow(
            campaignId: '123456789',
            campaignName: 'Test Campaign',
            status: 2, // SDK: ENABLED = 2
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame(123456789, $campaign->id);
        $this->assertIsInt($campaign->id);
    }

    #[Test]
    public function it_preserves_campaign_name_unchanged(): void
    {
        $campaignName = '[01] Search - Branded | Special (Characters)';
        $row = $this->createRealRow(
            campaignId: 123,
            campaignName: $campaignName,
            status: 2, // SDK: ENABLED = 2
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame($campaignName, $campaign->name);
    }

    #[Test]
    public function it_handles_large_campaign_ids(): void
    {
        $largeId = PHP_INT_MAX;
        $row = $this->createRealRow(
            campaignId: $largeId,
            campaignName: 'Large ID Campaign',
            status: 2, // SDK: ENABLED = 2
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame($largeId, $campaign->id);
    }

    #[Test]
    public function it_handles_special_characters_in_campaign_name(): void
    {
        $specialName = 'Campaign With "Quotes" & <Special> [Brackets]';
        $row = $this->createRealRow(
            campaignId: 123,
            campaignName: $specialName,
            status: 2, // SDK: ENABLED = 2
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame($specialName, $campaign->name);
    }

    #[Test]
    public function it_handles_whitespace_in_campaign_name(): void
    {
        $nameWithWhitespace = "Campaign  With   Multiple   Spaces\nAnd\tTabs";
        $row = $this->createRealRow(
            campaignId: 123,
            campaignName: $nameWithWhitespace,
            status: 2, // SDK: ENABLED = 2
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame($nameWithWhitespace, $campaign->name);
    }

    #[Test]
    public function it_returns_campaign_value_object(): void
    {
        $row = $this->createRealRow(
            campaignId: 123,
            campaignName: 'Test',
            status: 2, // SDK: ENABLED = 2
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertInstanceOf(Campaign::class, $campaign);
    }

    /**
     * Helper method to create a real GoogleAdsRow with campaign data.
     *
     * Uses actual protobuf objects instead of mocks to avoid segfaults
     * caused by PHPUnit mocking conflicts with the protobuf C extension.
     *
     * @param int|string $campaignId
     */
    private function createRealRow(
        int|string $campaignId,
        string $campaignName,
        int $status,
    ): GoogleAdsRow {
        $campaign = new GoogleAdsCampaign();
        $campaign->setId($campaignId);
        $campaign->setName($campaignName);
        $campaign->setStatus($status);

        $row = new GoogleAdsRow();
        $row->setCampaign($campaign);

        return $row;
    }
}
