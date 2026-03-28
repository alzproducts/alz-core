<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\BingAds\Transformers;

use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Infrastructure\BingAds\Exceptions\InvalidBingAdsResponseException;
use App\Infrastructure\BingAds\Transformers\BingAdsCsvTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * BingAdsCsvTransformer Unit Tests.
 *
 * Tests CSV parsing and transformation to domain value objects.
 * Covers header discovery, metadata/footer skipping, data validation,
 * and type conversions.
 */
#[CoversClass(BingAdsCsvTransformer::class)]
final class BingAdsCsvTransformerTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Empty/No Data Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_empty_array_for_empty_csv(): void
    {
        $result = BingAdsCsvTransformer::toCampaignMetrics('');

        $this->assertSame([], $result);
    }

    #[Test]
    public function it_returns_empty_array_for_header_only_csv(): void
    {
        $csv = 'CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions';

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertSame([], $result);
    }

    /*
    |--------------------------------------------------------------------------
    | Valid Data Transformation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_parses_single_row_csv(): void
    {
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
123,Test Campaign,2024-05-10,50.25,100,5000,10.5
CSV;

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(CampaignMetrics::class, $result[0]);
        $this->assertSame(123, $result[0]->campaignId);
        $this->assertSame('Test Campaign', $result[0]->campaignName);
        $this->assertSame('2024-05-10', $result[0]->date);
        $this->assertSame(50.25, $result[0]->costInPounds);
        $this->assertSame(100, $result[0]->clicks);
        $this->assertSame(5000, $result[0]->impressions);
        $this->assertSame(10.5, $result[0]->conversions);
    }

    #[Test]
    public function it_parses_multiple_row_csv(): void
    {
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
111,Campaign A,2024-05-10,10.00,50,2500,5.0
222,Campaign B,2024-05-10,20.00,100,5000,10.0
333,Campaign C,2024-05-10,30.00,150,7500,15.0
CSV;

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertCount(3, $result);
        $this->assertSame(111, $result[0]->campaignId);
        $this->assertSame(222, $result[1]->campaignId);
        $this->assertSame(333, $result[2]->campaignId);
        $this->assertSame('Campaign A', $result[0]->campaignName);
        $this->assertSame('Campaign B', $result[1]->campaignName);
        $this->assertSame('Campaign C', $result[2]->campaignName);
    }

    #[Test]
    public function it_handles_zero_values(): void
    {
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
456,Zero Campaign,2024-05-10,0.00,0,0,0.0
CSV;

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertCount(1, $result);
        $this->assertSame(0.00, $result[0]->costInPounds);
        $this->assertSame(0, $result[0]->clicks);
        $this->assertSame(0, $result[0]->impressions);
        $this->assertSame(0.0, $result[0]->conversions);
    }

    #[Test]
    public function it_handles_large_values(): void
    {
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
789,Big Campaign,2024-05-10,999999.99,1000000,100000000,50000.5
CSV;

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertCount(1, $result);
        $this->assertSame(999999.99, $result[0]->costInPounds);
        $this->assertSame(1000000, $result[0]->clicks);
        $this->assertSame(100000000, $result[0]->impressions);
        $this->assertSame(50000.5, $result[0]->conversions);
    }

    /*
    |--------------------------------------------------------------------------
    | Metadata Header Skipping Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_skips_metadata_rows_before_header(): void
    {
        $csv = <<<'CSV'
"Report Name: Campaign Performance"
"Date Range: 2024-05-01 to 2024-05-31"
"Account: Test Account (12345678)"

CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
123,Test Campaign,2024-05-10,50.25,100,5000,10.5
CSV;

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertCount(1, $result);
        $this->assertSame(123, $result[0]->campaignId);
        $this->assertSame('Test Campaign', $result[0]->campaignName);
    }

    #[Test]
    public function it_handles_empty_lines_before_header(): void
    {
        $csv = <<<'CSV'


CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
123,Test Campaign,2024-05-10,50.25,100,5000,10.5
CSV;

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertCount(1, $result);
        $this->assertSame(123, $result[0]->campaignId);
    }

    /*
    |--------------------------------------------------------------------------
    | Footer Skipping Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_skips_footer_rows_after_data(): void
    {
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
123,Test Campaign,2024-05-10,50.25,100,5000,10.5

"©2024 Microsoft Corporation. All rights reserved."
CSV;

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertCount(1, $result);
        $this->assertSame(123, $result[0]->campaignId);
    }

    #[Test]
    public function it_skips_rows_with_non_numeric_campaign_id(): void
    {
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
123,Valid Campaign,2024-05-10,50.25,100,5000,10.5
Total,All Campaigns,2024-05-10,50.25,100,5000,10.5
CSV;

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertCount(1, $result);
        $this->assertSame(123, $result[0]->campaignId);
    }

    #[Test]
    public function it_handles_full_report_with_metadata_and_footer(): void
    {
        $csv = <<<'CSV'
"Report Name: Campaign Performance"
"Date Range: 2024-05-01 to 2024-05-31"

CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
111,Campaign One,2024-05-10,10.00,50,2500,5.0
222,Campaign Two,2024-05-10,20.00,100,5000,10.0

"©2024 Microsoft Corporation. All rights reserved."
CSV;

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertCount(2, $result);
        $this->assertSame(111, $result[0]->campaignId);
        $this->assertSame(222, $result[1]->campaignId);
    }

    /*
    |--------------------------------------------------------------------------
    | Type Conversion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_converts_spend_string_to_float(): void
    {
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
123,Test,2024-05-10,123.456,10,100,1.0
CSV;

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertSame(123.456, $result[0]->costInPounds);
        $this->assertIsFloat($result[0]->costInPounds);
    }

    #[Test]
    public function it_converts_clicks_string_to_int(): void
    {
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
123,Test,2024-05-10,10.00,999,100,1.0
CSV;

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertSame(999, $result[0]->clicks);
        $this->assertIsInt($result[0]->clicks);
    }

    #[Test]
    public function it_converts_impressions_string_to_int(): void
    {
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
123,Test,2024-05-10,10.00,10,12345,1.0
CSV;

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertSame(12345, $result[0]->impressions);
        $this->assertIsInt($result[0]->impressions);
    }

    #[Test]
    public function it_converts_conversions_string_to_float(): void
    {
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
123,Test,2024-05-10,10.00,10,100,99.99
CSV;

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertSame(99.99, $result[0]->conversions);
        $this->assertIsFloat($result[0]->conversions);
    }

    /*
    |--------------------------------------------------------------------------
    | Error Handling Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_when_header_row_not_found(): void
    {
        $csv = <<<'CSV'
"Some random text"
"More random text"
CSV;

        $this->expectException(InvalidBingAdsResponseException::class);
        $this->expectExceptionMessage('Invalid Bing Ads API response');

        BingAdsCsvTransformer::toCampaignMetrics($csv);
    }

    #[Test]
    public function it_throws_when_required_column_missing(): void
    {
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions
123,Test,2024-05-10,10.00,10,100
CSV;

        $this->expectException(InvalidBingAdsResponseException::class);
        $this->expectExceptionMessage('Invalid Bing Ads API response');

        BingAdsCsvTransformer::toCampaignMetrics($csv);
    }

    #[Test]
    public function it_throws_when_date_format_invalid(): void
    {
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
123,Test,05/10/2024,10.00,10,100,1.0
CSV;

        $this->expectException(InvalidBingAdsResponseException::class);
        $this->expectExceptionMessage('Invalid Bing Ads API response');

        BingAdsCsvTransformer::toCampaignMetrics($csv);
    }

    #[Test]
    public function it_throws_when_date_format_uses_slashes(): void
    {
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
123,Test,2024/05/10,10.00,10,100,1.0
CSV;

        $this->expectException(InvalidBingAdsResponseException::class);
        $this->expectExceptionMessage('Invalid Bing Ads API response');

        BingAdsCsvTransformer::toCampaignMetrics($csv);
    }

    #[Test]
    public function it_throws_when_data_row_has_missing_values(): void
    {
        // Row has CampaignId but is missing later columns
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
123,Test Campaign
CSV;

        $this->expectException(InvalidBingAdsResponseException::class);
        $this->expectExceptionMessage('Invalid Bing Ads API response');

        BingAdsCsvTransformer::toCampaignMetrics($csv);
    }

    #[Test]
    public function it_throws_when_row_has_truncated_columns(): void
    {
        // Has numeric CampaignId but missing conversions column
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
456,Truncated,2024-05-10,10.00,10,100
CSV;

        $this->expectException(InvalidBingAdsResponseException::class);
        $this->expectExceptionMessage('Invalid Bing Ads API response');

        BingAdsCsvTransformer::toCampaignMetrics($csv);
    }

    /*
    |--------------------------------------------------------------------------
    | Special Character Handling Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_preserves_campaign_names_with_special_characters(): void
    {
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
123,"Campaign with ""quotes"" and, commas",2024-05-10,10.00,10,100,1.0
CSV;

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertSame('Campaign with "quotes" and, commas', $result[0]->campaignName);
    }

    #[Test]
    public function it_preserves_campaign_names_with_unicode(): void
    {
        $csv = <<<'CSV'
CampaignId,CampaignName,TimePeriod,Spend,Clicks,Impressions,Conversions
123,Campaign £€¥ Test,2024-05-10,10.00,10,100,1.0
CSV;

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertSame('Campaign £€¥ Test', $result[0]->campaignName);
    }

    /*
    |--------------------------------------------------------------------------
    | Column Order Independence Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_handles_columns_in_different_order(): void
    {
        $csv = <<<'CSV'
Conversions,Impressions,Clicks,Spend,TimePeriod,CampaignName,CampaignId
10.5,5000,100,50.25,2024-05-10,Test Campaign,123
CSV;

        $result = BingAdsCsvTransformer::toCampaignMetrics($csv);

        $this->assertCount(1, $result);
        $this->assertSame(123, $result[0]->campaignId);
        $this->assertSame('Test Campaign', $result[0]->campaignName);
        $this->assertSame('2024-05-10', $result[0]->date);
        $this->assertSame(50.25, $result[0]->costInPounds);
        $this->assertSame(100, $result[0]->clicks);
        $this->assertSame(5000, $result[0]->impressions);
        $this->assertSame(10.5, $result[0]->conversions);
    }
}
