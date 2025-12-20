<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds\Transformers;

use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Infrastructure\BingAds\Exceptions\InvalidBingAdsResponseException;
use LogicException;

/**
 * Transforms Bing Ads CSV report data into domain value objects.
 *
 * Bing Ads CSV format includes metadata rows before the actual data:
 * - Report name, date range, and other info as header lines
 * - Column header row with field names
 * - Data rows
 *
 * This transformer locates the header row by finding expected column names,
 * then parses subsequent rows into CampaignMetrics objects.
 */
final readonly class BingAdsCsvTransformer
{
    /**
     * Expected column names in the CSV (must match BingAdsTransport::REPORT_COLUMNS).
     */
    private const string COL_CAMPAIGN_ID = 'CampaignId';
    private const string COL_CAMPAIGN_NAME = 'CampaignName';
    private const string COL_TIME_PERIOD = 'TimePeriod';
    private const string COL_SPEND = 'Spend';
    private const string COL_CLICKS = 'Clicks';
    private const string COL_IMPRESSIONS = 'Impressions';
    private const string COL_CONVERSIONS = 'Conversions';

    /**
     * All required columns for validation.
     *
     * @var list<string>
     */
    private const array REQUIRED_COLUMNS = [
        self::COL_CAMPAIGN_ID,
        self::COL_CAMPAIGN_NAME,
        self::COL_TIME_PERIOD,
        self::COL_SPEND,
        self::COL_CLICKS,
        self::COL_IMPRESSIONS,
        self::COL_CONVERSIONS,
    ];

    /**
     * Parse CSV content into CampaignMetrics array.
     *
     * @return list<CampaignMetrics>
     *
     * @throws InvalidBingAdsResponseException When CSV is malformed or missing required data
     */
    public static function toCampaignMetrics(string $csvContent): array
    {
        $lines = self::parseLines($csvContent);

        if ($lines === []) {
            return [];
        }

        $headerIndex = self::findHeaderRowIndex($lines);

        if ($headerIndex === null) {
            throw InvalidBingAdsResponseException::malformedCsv('Could not find header row with expected columns');
        }

        if (!\array_key_exists($headerIndex, $lines)) {
            throw new LogicException('Header index must exist in lines');
        }
        $columnMap = self::buildColumnMap($lines[$headerIndex]);
        $metrics = [];

        // Process data rows (everything after header)
        // buildColumnMap() validates COL_CAMPAIGN_ID exists or throws
        if (!\array_key_exists(self::COL_CAMPAIGN_ID, $columnMap)) {
            throw new LogicException('CampaignId must exist in column map');
        }
        $campaignIdIndex = $columnMap[self::COL_CAMPAIGN_ID];

        for ($i = $headerIndex + 1, $iMax = \count($lines); $i < $iMax; $i++) {
            if (!\array_key_exists($i, $lines)) {
                throw new LogicException('Row index must exist in lines');
            }
            $row = $lines[$i];

            // Skip empty rows and footer rows (copyright notice, etc.)
            // A valid data row must have a numeric CampaignId in the expected column
            if (!self::isDataRow($row, $campaignIdIndex)) {
                continue;
            }

            $metrics[] = self::rowToCampaignMetrics($row, $columnMap, $i + 1);
        }

        return $metrics;
    }

    /**
     * Parse CSV content into array of rows.
     *
     * @return list<list<string>>
     *
     * @throws InvalidBingAdsResponseException When temp stream creation fails
     */
    private static function parseLines(string $csvContent): array
    {
        $lines = [];
        $stream = \fopen('php://temp', 'rb+');

        if ($stream === false) {
            throw InvalidBingAdsResponseException::malformedCsv('Failed to create temp stream');
        }

        \fwrite($stream, $csvContent);
        \rewind($stream);

        while (true) {
            $row = \fgetcsv($stream, null, ',', '"', '');

            if ($row === false) {
                break;
            }

            /** @var list<string> $row */
            $lines[] = $row;
        }

        \fclose($stream);

        return $lines;
    }

    /**
     * Check if a row is a valid data row (not empty, not footer).
     *
     * @param list<string> $row
     */
    private static function isDataRow(array $row, int $campaignIdIndex): bool
    {
        // Row must have enough columns
        if (!isset($row[$campaignIdIndex])) {
            return false;
        }

        // CampaignId must be numeric (filters out copyright footer, empty rows)
        return \is_numeric($row[$campaignIdIndex]);
    }

    /**
     * Find the index of the header row containing expected column names.
     *
     * @param list<list<string>> $lines
     */
    private static function findHeaderRowIndex(array $lines): ?int
    {
        // Header row must contain CampaignId column
        return \array_find_key($lines, static fn(array $row): bool => \in_array(self::COL_CAMPAIGN_ID, $row, true));
    }

    /**
     * Build a map of column name to index.
     *
     * @param list<string> $headerRow
     *
     * @return array<string, int>
     *
     * @throws InvalidBingAdsResponseException When required columns are missing
     */
    private static function buildColumnMap(array $headerRow): array
    {
        $columnMap = [];

        foreach ($headerRow as $index => $columnName) {
            $columnMap[$columnName] = $index;
        }

        // Validate all required columns exist
        foreach (self::REQUIRED_COLUMNS as $required) {
            if (!isset($columnMap[$required])) {
                throw InvalidBingAdsResponseException::missingColumn($required);
            }
        }

        return $columnMap;
    }

    /**
     * Transform a single CSV row into CampaignMetrics.
     *
     * @param list<string> $row
     * @param array<string, int> $columnMap
     *
     * @throws InvalidBingAdsResponseException When row data is invalid
     */
    private static function rowToCampaignMetrics(array $row, array $columnMap, int $lineNumber): CampaignMetrics
    {
        $campaignId = self::getRequiredValue($row, $columnMap, self::COL_CAMPAIGN_ID, $lineNumber);
        $campaignName = self::getRequiredValue($row, $columnMap, self::COL_CAMPAIGN_NAME, $lineNumber);
        $timePeriod = self::getRequiredValue($row, $columnMap, self::COL_TIME_PERIOD, $lineNumber);
        $spend = self::getRequiredValue($row, $columnMap, self::COL_SPEND, $lineNumber);
        $clicks = self::getRequiredValue($row, $columnMap, self::COL_CLICKS, $lineNumber);
        $impressions = self::getRequiredValue($row, $columnMap, self::COL_IMPRESSIONS, $lineNumber);
        $conversions = self::getRequiredValue($row, $columnMap, self::COL_CONVERSIONS, $lineNumber);

        // Validate campaign ID is numeric
        if (!\is_numeric($campaignId)) {
            throw InvalidBingAdsResponseException::invalidValue(
                self::COL_CAMPAIGN_ID,
                "Expected numeric, got '{$campaignId}' at line {$lineNumber}",
            );
        }

        // Validate date is in expected YYYY-MM-DD format
        $date = self::validateDateFormat($timePeriod, $lineNumber);

        return new CampaignMetrics(
            campaignId: (int) $campaignId,
            campaignName: $campaignName,
            date: $date,
            costInPounds: (float) $spend,
            clicks: (int) $clicks,
            impressions: (int) $impressions,
            conversions: (float) $conversions,
        );
    }

    /**
     * Get a required value from the row or throw.
     *
     * @param list<string> $row
     * @param array<string, int> $columnMap
     *
     * @throws InvalidBingAdsResponseException When value is missing
     */
    private static function getRequiredValue(array $row, array $columnMap, string $column, int $lineNumber): string
    {
        if (!\array_key_exists($column, $columnMap)) {
            throw new LogicException("Column {$column} must exist in column map");
        }
        $index = $columnMap[$column];

        if (!isset($row[$index])) {
            throw InvalidBingAdsResponseException::invalidValue(
                $column,
                "Missing value at line {$lineNumber}",
            );
        }

        return $row[$index];
    }

    /**
     * Validate date is in YYYY-MM-DD format.
     *
     * @throws InvalidBingAdsResponseException When date format is unexpected (outputs actual format for debugging)
     */
    private static function validateDateFormat(string $timePeriod, int $lineNumber): string
    {
        if (\preg_match('/^\d{4}-\d{2}-\d{2}$/', $timePeriod) === 1) {
            return $timePeriod;
        }

        throw InvalidBingAdsResponseException::invalidValue(
            self::COL_TIME_PERIOD,
            "Expected YYYY-MM-DD format, got '{$timePeriod}' at line {$lineNumber}. Update transformer to handle this format.",
        );
    }
}
