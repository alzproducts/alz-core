<?php

declare(strict_types=1);

namespace App\Application\Support;

use Webmozart\Assert\Assert;

/**
 * Format-agnostic CSV plumbing: stream read, blank-row skip, header validation, 1-based line numbers.
 */
final class CsvParser
{
    /**
     * @return array{string, ?string} File content and optional error message
     */
    public static function readFile(string $file): array
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
     * @param list<string> $expectedHeader Normalised (lowercase, trimmed) column names the first non-blank row must match
     *
     * @return array{list<array{int, list<string>}>, ?string} Data rows (1-based line number + cells), and a header error if any
     */
    public static function rows(string $csvContent, array $expectedHeader): array
    {
        $lines = self::readDataLines($csvContent);

        if ($lines === []) {
            return [[], null];
        }

        [, $header] = $lines[0];
        $headerError = self::validateHeader($header, $expectedHeader);
        if ($headerError !== null) {
            return [[], $headerError];
        }

        return [\array_slice($lines, 1), null];
    }

    /**
     * @return list<array{int, list<string>}>
     */
    private static function readDataLines(string $csvContent): array
    {
        $handle = self::openTempStream($csvContent);
        $lines = [];
        $lineNumber = 0;
        try {
            while (($row = \fgetcsv($handle, escape: '')) !== false) {
                $lineNumber++;
                $cells = self::coalesceRow($row);
                if (! self::isBlankRow($cells)) {
                    $lines[] = [$lineNumber, $cells];
                }
            }
        } finally {
            \fclose($handle);
        }

        return $lines;
    }

    /**
     * @return resource
     */
    private static function openTempStream(string $content)
    {
        $handle = \fopen('php://temp', 'r+b');
        Assert::resource($handle);
        \fwrite($handle, $content);
        \rewind($handle);

        return $handle;
    }

    /**
     * @param array<int, string|null> $row
     *
     * @return list<string>
     */
    private static function coalesceRow(array $row): array
    {
        return \array_values(\array_map(
            static fn(?string $value): string => $value ?? '',
            $row,
        ));
    }

    /**
     * @param list<string> $row
     */
    private static function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (\mb_trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $row
     * @param list<string> $expectedHeader
     */
    private static function validateHeader(array $row, array $expectedHeader): ?string
    {
        $normalized = \array_map(
            static fn(string $value): string => \mb_strtolower(\mb_trim($value)),
            $row,
        );

        if ($normalized !== $expectedHeader) {
            return 'Invalid CSV header. Expected: ' . \implode(',', $expectedHeader);
        }

        return null;
    }
}
