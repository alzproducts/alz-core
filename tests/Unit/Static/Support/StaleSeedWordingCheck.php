<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

/**
 * Repo text no longer names the retired prod-sync seed command or describes local data as synced (AC12).
 */
final class StaleSeedWordingCheck
{
    // intentional: built by concatenation so this file does not itself contain the retired name
    public const string RETIRED_COMMAND = 'dev:seed-' . 'sync';

    /**
     * Paths never scanned: local AI workspaces, other worktrees and IDE state.
     */
    public const array EXEMPT_PREFIXES = ['.ai/', '.claude/worktrees/', '.idea/'];

    /**
     * @var array<string, list<string>> per-file phrases the rename left stale
     */
    public const array STALE_PHRASES = [
        'Makefile' => ['needs local Supabase DB with synced data'],
        'AGENTS.md' => ['needs local Supabase with synced data', 'Full DB reset (Supabase + Laravel migrations) —'],
        'tests/AGENTS.md' => ['needs local Supabase with synced data'],
        'database/AGENTS.md' => ['All data must be re-synced afterwards', '**Step 2 — `make migrate`**'],
        'app/Infrastructure/Shopwired/Factories/CustomFieldFactory.php' => ['Run SyncShopwiredCustomFieldsJob or'],
        'app/Presentation/Console/Commands/Dev/SyncFromProdCommand.php' => ['to seed a fresh local database'],
        'tests/Integration/Catalog/OffersFilterOnSaleGuardTest.php' => ['Runs against the synced catalog'],
        'tests/Integration/Catalog/ProductsViewHasAnySaleGuardTest.php' => ['Runs against the synced catalog'],
    ];

    public const array DOC_FILES = ['README.md', 'AGENTS.md', 'tests/AGENTS.md', 'database/AGENTS.md'];

    public const array DOC_REQUIRED_TERMS = ['dev:seed-fake', 'dev:sync-from-prod', 'make db-reset-full'];

    /**
     * @param list<string> $paths repo-relative paths to scan
     * @param callable(string): string $read
     *
     * @return list<string>
     */
    public static function violations(array $paths, callable $read): array
    {
        if ($paths === []) {
            return ['the file listing is empty'];
        }

        $listed = \array_flip($paths);
        $violations = [];

        foreach ($paths as $path) {
            if (!self::isExempt($path) && \str_contains($read($path), self::RETIRED_COMMAND)) {
                $violations[] = 'retired command named in: ' . $path;
            }
        }

        foreach (self::STALE_PHRASES as $path => $phrases) {
            if (!isset($listed[$path])) {
                $violations[] = 'site file missing from the listing: ' . $path;

                continue;
            }

            $contents = $read($path);

            foreach ($phrases as $phrase) {
                if (\str_contains($contents, $phrase)) {
                    $violations[] = "stale phrase in {$path}: {$phrase}";
                }
            }
        }

        foreach (self::DOC_FILES as $path) {
            if (!isset($listed[$path])) {
                $violations[] = 'doc file missing from the listing: ' . $path;

                continue;
            }

            $contents = $read($path);

            foreach (self::DOC_REQUIRED_TERMS as $term) {
                if (!\str_contains($contents, $term)) {
                    $violations[] = "doc file {$path} does not mention: {$term}";
                }
            }
        }

        return $violations;
    }

    private static function isExempt(string $path): bool
    {
        return \array_any(self::EXEMPT_PREFIXES, static fn(string $prefix): bool => \str_starts_with($path, $prefix));
    }
}
