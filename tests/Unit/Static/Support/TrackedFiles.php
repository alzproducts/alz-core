<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Repo files a static check scans: tracked files plus untracked ones git does not ignore, so a check also sees
 * files a branch adds before they are committed. Files deleted from the work tree are dropped.
 */
final class TrackedFiles
{
    /**
     * @return list<string> repo-relative paths
     */
    public static function list(string $root, string ...$pathspecs): array
    {
        $process = new Process(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard', '--', ...$pathspecs], $root);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('git ls-files failed: ' . \mb_trim($process->getErrorOutput()));
        }

        $paths = \array_filter(\explode("\0", $process->getOutput()), static fn(string $path): bool => $path !== '' && \is_file($root . '/' . $path));

        return \array_values(\array_unique($paths));
    }

    /**
     * @return callable(string): string reads a repo-relative path under `$root`
     */
    public static function reader(string $root): callable
    {
        return static function (string $path) use ($root): string {
            $contents = \file_get_contents($root . '/' . $path);

            if ($contents === false) {
                throw new RuntimeException('Cannot read ' . $path);
            }

            return $contents;
        };
    }
}
