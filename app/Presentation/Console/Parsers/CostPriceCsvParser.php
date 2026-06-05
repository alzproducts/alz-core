<?php

declare(strict_types=1);

namespace App\Presentation\Console\Parsers;

use App\Application\Linnworks\BulkCostPriceUpdate\SupplierCostPriceBatchDTO;
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
        $handle = \fopen($file, 'rb');
        if ($handle === false) {
            return [[], ["Could not open CSV file: {$file}"]];
        }

        try {
            $lines = $this->readDataLines($handle);
        } finally {
            \fclose($handle);
        }

        [$grouped, $errors] = $this->groupLines($lines);
        if ($errors !== []) {
            return [[], $errors];
        }

        return [$this->buildBatches($grouped), []];
    }

    /**
     * @param resource $handle
     *
     * @return list<array{int, array<int, string|null>}> Non-blank rows paired with their 1-based line number
     */
    private function readDataLines($handle): array
    {
        $lines = [];
        $lineNumber = 0;
        while (($row = \fgetcsv($handle, escape: '')) !== false) {
            $lineNumber++;
            if (! $this->isBlankRow($row)) {
                $lines[] = [$lineNumber, $row];
            }
        }

        return $lines;
    }

    /**
     * @param list<array{int, array<int, string|null>}> $lines
     *
     * @return array{array<string, non-empty-list<UpdateCostPriceCommand>>, list<string>}
     */
    private function groupLines(array $lines): array
    {
        if ($lines === []) {
            return [[], []];
        }

        [, $header] = $lines[0];
        $headerError = $this->validateHeader($header);
        if ($headerError !== null) {
            return [[], [$headerError]];
        }

        return $this->collectCommands(\array_slice($lines, 1));
    }

    /**
     * @param list<array{int, array<int, string|null>}> $lines
     *
     * @return array{array<string, non-empty-list<UpdateCostPriceCommand>>, list<string>}
     */
    private function collectCommands(array $lines): array
    {
        $grouped = [];
        $errors = [];
        foreach ($lines as [$lineNumber, $row]) {
            $parsed = $this->parseRow($row, $lineNumber);
            if (\is_string($parsed)) {
                $errors[] = $parsed;

                continue;
            }

            [$supplier, $command] = $parsed;
            $grouped[$supplier][] = $command;
        }

        return [$grouped, $errors];
    }

    /**
     * @param array<int, string|null> $row
     *
     * @return array{string, UpdateCostPriceCommand}|string Parsed [supplier, command], or an error message
     */
    private function parseRow(array $row, int $lineNumber): array|string
    {
        if (\count($row) !== 3) {
            return "Row {$lineNumber}: expected 3 columns (supplier,sku,cost_price), got " . \count($row);
        }

        $supplier = \mb_trim($row[0] ?? '');
        $sku = \mb_trim($row[1] ?? '');
        $cost = \mb_trim($row[2] ?? '');

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

        if (! \is_numeric($cost) || (float) $cost < 0) {
            return "Row {$lineNumber}: cost_price must be a number >= 0, got '{$cost}'";
        }

        return null;
    }

    /**
     * @param array<int, string|null> $row
     */
    private function validateHeader(array $row): ?string
    {
        $normalized = \array_map(
            static fn(?string $value): string => \mb_strtolower(\mb_trim($value ?? '')),
            \array_values($row),
        );

        if ($normalized !== self::EXPECTED_HEADER) {
            return 'Invalid CSV header. Expected: ' . \implode(',', self::EXPECTED_HEADER);
        }

        return null;
    }

    /**
     * @param array<int, string|null> $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (\mb_trim($value ?? '') !== '') {
                return false;
            }
        }

        return true;
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
