<?php

declare(strict_types=1);

namespace App\Presentation\Console\Parsers;

use App\Application\Shopwired\PricingUpdate\PriceCommandPreFlightService;
use App\Application\Support\CsvParser;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\Shared\Money\ValueObjects\Money;

/**
 * Parse and validate a `sku,price` CSV into a flat list of selling-price commands.
 *
 * Validation is all-or-nothing: any malformed row (bad column count, non-numeric/negative
 * price, invalid SKU, price failing the VAT gross→net→gross round-trip) is collected with
 * its line number and the whole file is rejected — never a partially-valid dispatch.
 * Prices are VAT-inclusive (customer-facing). The round-trip is pre-validated here so a
 * bad price rejects the batch up front instead of permanently failing one product's job
 * post-dispatch; the downstream use case re-runs the same check (defence in depth).
 */
final class SellingPriceCsvParser
{
    /** @var list<string> */
    private const array EXPECTED_HEADER = ['sku', 'price'];

    /**
     * @return array{list<UpdatePriceCommand>, list<string>} Commands (empty on error) and row-level errors
     */
    public function parse(string $file): array
    {
        [$content, $fileError] = CsvParser::readFile($file);
        if ($fileError !== null) {
            return [[], [$fileError]];
        }

        [$rows, $headerError] = CsvParser::rows($content, self::EXPECTED_HEADER);
        if ($headerError !== null) {
            return [[], [$headerError]];
        }

        return $this->collectCommands($rows);
    }

    /**
     * @param list<array{int, list<string>}> $rows
     *
     * @return array{list<UpdatePriceCommand>, list<string>}
     */
    private function collectCommands(array $rows): array
    {
        $commands = [];
        $errors = [];
        foreach ($rows as [$lineNumber, $cells]) {
            $parsed = $this->parseRow($cells, $lineNumber);
            if (\is_string($parsed)) {
                $errors[] = $parsed;

                continue;
            }
            $commands[] = $parsed;
        }
        if ($errors !== []) {
            return [[], $errors];
        }

        return [$commands, []];
    }

    /**
     * @param list<string> $row
     *
     * @return UpdatePriceCommand|string Parsed command, or an error message
     */
    private function parseRow(array $row, int $lineNumber): UpdatePriceCommand|string
    {
        if (\count($row) !== 2) {
            return "Row {$lineNumber}: expected 2 columns (sku,price), got " . \count($row);
        }

        $sku = \mb_trim($row[0]);
        $price = \mb_trim($row[1]);

        // Plain decimal only — is_numeric would accept scientific notation (1e3) and signs.
        if (\preg_match('/^\d+(\.\d+)?$/', $price) !== 1) {
            return "Row {$lineNumber}: price must be a number >= 0, got '{$price}'";
        }

        return $this->buildCommand($sku, $price, $lineNumber);
    }

    /**
     * @return UpdatePriceCommand|string Validated command, or an error message
     */
    private function buildCommand(string $sku, string $price, int $lineNumber): UpdatePriceCommand|string
    {
        try {
            $command = new UpdatePriceCommand(Sku::fromString($sku), price: Money::inclusive((float) $price));
            PriceCommandPreFlightService::validateVatRoundTrip([$command]);
        } catch (InvalidSkuException $e) {
            return "Row {$lineNumber}: {$e->getMessage()}";
        } catch (ValidationFailedException $e) {
            return "Row {$lineNumber}: {$e->reason}";
        }

        return $command;
    }
}
