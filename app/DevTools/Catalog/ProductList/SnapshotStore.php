<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;

/**
 * One directory of JSON bodies per capture label under the gitignored tmp/cor-258/.
 */
final readonly class SnapshotStore
{
    public const string MANIFEST = 'manifest';

    public function __construct(
        private Filesystem $files,
    ) {}

    public function directory(string $label): string
    {
        if (\preg_match('/^[A-Za-z0-9._-]+$/', $label) !== 1 || \mb_trim($label, '.') === '') {
            throw new InvalidArgumentException('A capture label may hold only letters, digits, dot, underscore and hyphen.');
        }

        return \base_path('tmp/cor-258/' . $label);
    }

    public function exists(string $label): bool
    {
        return $this->files->isFile($this->path($label, self::MANIFEST));
    }

    /**
     * Empties the label's directory of earlier JSON files, creating it when absent.
     */
    public function reset(string $label): void
    {
        $this->files->ensureDirectoryExists($this->directory($label));
        $this->files->delete($this->jsonFiles($label));
    }

    /**
     * @throws RuntimeException When the file cannot be written
     */
    public function put(string $label, string $name, string $contents): void
    {
        if ($this->files->put($this->path($label, $name), $contents) === false) {
            throw new RuntimeException("Could not write {$name}.json for capture {$label}.");
        }
    }

    /**
     * @throws RuntimeException When the file is missing
     */
    public function read(string $label, string $name): string
    {
        try {
            return $this->files->get($this->path($label, $name));
        } catch (FileNotFoundException $e) {
            throw new RuntimeException("Capture {$label} has no {$name}.json.", previous: $e);
        }
    }

    /**
     * @return list<string> Captured shape and reader names, manifest excluded, sorted
     */
    public function names(string $label): array
    {
        $names = \array_map(static fn(string $path): string => \basename($path, '.json'), $this->jsonFiles($label));
        $names = \array_values(\array_filter($names, static fn(string $name): bool => $name !== self::MANIFEST));
        \sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function jsonFiles(string $label): array
    {
        $paths = \glob($this->directory($label) . '/*.json');

        return $paths === false ? [] : $paths;
    }

    private function path(string $label, string $name): string
    {
        return $this->directory($label) . '/' . $name . '.json';
    }
}
