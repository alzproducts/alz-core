<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use RuntimeException;

/**
 * Server-side request durations from Octane's request lines. The lines carry neither a timestamp nor a query
 * string, so a run window is a byte range of the log.
 */
final class OctaneLogDurations
{
    private const string REQUEST_LINE = '#^\s*(?<status>\d{3})\s+GET\s+(?<path>/\S*)\s.*?(?<ms>\d+(?:\.\d+)?)\s*ms\s*$#';

    public static function currentOffset(): int
    {
        \clearstatcache(true, OctaneBenchServer::logPath());
        $size = \is_file(OctaneBenchServer::logPath()) ? \filesize(OctaneBenchServer::logPath()) : false;

        return $size === false ? 0 : $size;
    }

    /**
     * @return array{durations: list<float>, statuses: array<int, int>} Durations in ms and a count per status code
     *
     * @throws RuntimeException When the log cannot be read
     */
    public static function between(int $fromOffset, int $toOffset, string $path): array
    {
        $chunk = \file_get_contents(OctaneBenchServer::logPath(), offset: $fromOffset, length: \max($toOffset - $fromOffset, 0));

        if ($chunk === false) {
            throw new RuntimeException('Could not read storage/logs/octane.log.');
        }

        $durations = [];
        $statuses = [];

        foreach (\explode("\n", (string) \preg_replace('/\e\[[0-9;]*m/', '', $chunk)) as $line) {
            if (\preg_match(self::REQUEST_LINE, $line, $match) === 1 && $match['path'] === $path) {
                $durations[] = (float) $match['ms'];
                $statuses[(int) $match['status']] = ($statuses[(int) $match['status']] ?? 0) + 1;
            }
        }

        return ['durations' => $durations, 'statuses' => $statuses];
    }
}
