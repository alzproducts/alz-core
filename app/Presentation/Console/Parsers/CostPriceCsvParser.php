<?php

declare(strict_types=1);

namespace App\Presentation\Console\Parsers;

use App\Application\Linnworks\BulkCostPriceUpdate\SupplierCostPriceBatchDTO;
use App\Application\Support\CsvParser;
use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Shared\Money\ValueObjects\Money;

/**
 * Parse and validate a `supplier,sku,cost_price` CSV into supplier-grouped batches.
 *
 * Validation is all-or-nothing: any malformed row (bad column count, empty supplier,
 * non-numeric/negative cost, invalid SKU) is collected with its line number and the whole
 * file is rejected — never a partially-valid dispatch. Cost prices are net / ex-VAT.
 */
final class CostPriceCsvParser
{
    /** @var list<string> */
    private const array EXPECTED_HEADER = ['supplier', 'sku', 'cost_price'];

    /**
     * @return array{list<SupplierCostPriceBatchDTO>, list<string>} Batches (empty on error) and row-level errors
     */
    public function parse(string $file): array
    {
        [$content, $fileError] = $this->readFile($file);
        if ($fileError !== null) {
            return [[], [$fileError]];
        }

        [$rows, $headerError] = CsvParser::rows($content, self::EXPECTED_HEADER);
        if ($headerError !== null) {
            return [[], [$headerError]];
        }

        return $this->collectBatches($rows);
    }

    /**
     * @return array{string, ?string} Content and optional error message
     */
    private function readFile(string $file): array
    {
        $handle = \fopen($file, 'rb');
        if ($handle === false) {
            return ['', "Could not open CSV file: {$file}"];
        }

        try {
            $content = \stream_get_contents($handle);
        } finally {
            \fclose($handle);
        }

        if ($content === false) {
            return ['', "Could not read CSV file: {$file}"];
        }

        return [$content, null];
    }

    /**
     * @param list<array{int, list<string>}> $rows
     *
     * @return array{list<SupplierCostPriceBatchDTO>, list<string>}
     */
    private function collectBatches(array $rows): array
    {
        $grouped = [];
        $errors = [];
        foreach ($rows as [$lineNumber, $cells]) {
            $parsed = $this->parseRow($cells, $lineNumber);
            if (\is_string($parsed)) {
                $errors[] = $parsed;

                continue;
            }
            [$supplier, $command] = $parsed;
            $grouped[$supplier][] = $command;
        }
        if ($errors !== []) {
            return [[], $errors];
        }

        return [$this->buildBatches($grouped), []];
    }

    /**
     * @param list<string> $row
     *
     * @return array{string, UpdateCostPriceCommand}|string Parsed [supplier, command], or an error message
     */
    private function parseRow(array $row, int $lineNumber): array|string
    {
        if (\count($row) !== 3) {
            return "Row {$lineNumber}: expected 3 columns (supplier,sku,cost_price), got " . \count($row);
        }

        $supplier = \mb_trim($row[0]);
        $sku = \mb_trim($row[1]);
        $cost = \mb_trim($row[2]);

        $fieldError = $this->validateFields($supplier, $cost, $lineNumber);
        if ($fieldError !== null) {
            return $fieldError;
        }

        try {
            return [$supplier, new UpdateCostPriceCommand(Sku::fromString($sku), Money::exclusive((float) $cost))];
        } catch (InvalidSkuException $e) {
            return "Row {$lineNumber}: {$e->getMessage()}";
        }
    }

    private function validateFields(string $supplier, string $cost, int $lineNumber): ?string
    {
        if ($supplier === '') {
            return "Row {$lineNumber}: supplier is empty";
        }

        // Plain decimal only — is_numeric would accept scientific notation (1e3) and signs.
        if (\preg_match('/^\d+(\.\d+)?$/', $cost) !== 1) {
            return "Row {$lineNumber}: cost_price must be a number >= 0, got '{$cost}'";
        }

        return null;
    }

    /**
     * @param array<string, non-empty-list<UpdateCostPriceCommand>> $grouped
     *
     * @return list<SupplierCostPriceBatchDTO>
     */
    private function buildBatches(array $grouped): array
    {
        $batches = [];
        foreach ($grouped as $supplier => $commands) {
            $batches[] = new SupplierCostPriceBatchDTO($supplier, $commands);
        }

        return $batches;
    }
}
