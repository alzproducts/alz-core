<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

/**
 * RFC 4180-compliant CSV formatting utility.
 *
 * Provides generic CSV generation with proper escaping and CRLF line endings.
 * Suitable for any CSV export/import requirement.
 */
final class CsvFormatter
{
    /**
     * Format data as RFC 4180-compliant CSV.
     *
     * @param array<string> $headers Column header names
     * @param array<int, array<string>> $rows Data rows (each row is array of string values matching header order)
     *
     * @return string CSV content with CRLF line endings
     */
    public static function format(array $headers, array $rows): string
    {
        $lines = [];

        // Add CSV header row
        $headerLine = \implode(',', \array_map(self::escapeValue(...), $headers));
        $lines[] = $headerLine;

        // Add data rows
        foreach ($rows as $row) {
            $rowLine = \implode(',', \array_map(self::escapeValue(...), $row));
            $lines[] = $rowLine;
        }

        // RFC 4180: Rows separated by CRLF
        return \implode("\r\n", $lines) . "\r\n";
    }

    /**
     * RFC 4180 CSV value escaping.
     *
     * Rules:
     * - Fields with commas, double quotes, or newlines must be quoted
     * - Double quotes within quoted fields are escaped by doubling them
     * - Whitespace is preserved (no trimming)
     */
    public static function escapeValue(string $value): string
    {
        // Check if value needs quoting
        if (\str_contains($value, ',') || \str_contains($value, '"') || \str_contains($value, "\n")) {
            // Escape double quotes by doubling them
            $value = \str_replace('"', '""', $value);

            // Wrap in quotes
            return "\"{$value}\"";
        }

        return $value;
    }
}
