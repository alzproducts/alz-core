<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Static\Support\StaleSeedWordingCheck;
use Tests\Unit\Static\Support\TrackedFiles;

/**
 * The prod-sync command's rename to dev:sync-from-prod left no stale command name or "synced data" wording (AC12).
 */
#[CoversNothing]
#[Group('repo-static')]
final class StaleSeedWordingTest extends TestCase
{
    #[Test]
    public function the_repo_names_only_the_current_seed_commands(): void
    {
        $root = \base_path();

        $this->assertSame([], StaleSeedWordingCheck::violations(TrackedFiles::list($root), TrackedFiles::reader($root)));
    }

    #[Test]
    public function a_planted_retired_command_and_stale_phrase_fail_the_check(): void
    {
        $root = \base_path();
        $reader = TrackedFiles::reader($root);
        $planted = static fn(string $path): string => match ($path) {
            'app/PlantedCommand.php' => 'php artisan ' . StaleSeedWordingCheck::RETIRED_COMMAND,
            '.ai/notes.md' => StaleSeedWordingCheck::RETIRED_COMMAND,
            'tests/AGENTS.md' => $reader($path) . "\nneeds local Supabase with synced data",
            'README.md' => \str_replace('dev:sync-from-prod', 'the prod sync', $reader($path)),
            default => $reader($path),
        };
        $paths = [...TrackedFiles::list($root), 'app/PlantedCommand.php', '.ai/notes.md'];

        $this->assertSame(
            [
                'retired command named in: app/PlantedCommand.php',
                'stale phrase in tests/AGENTS.md: needs local Supabase with synced data',
                'doc file README.md does not mention: dev:sync-from-prod',
            ],
            StaleSeedWordingCheck::violations($paths, $planted),
        );
    }

    #[Test]
    public function an_empty_listing_fails_closed(): void
    {
        $this->assertSame(['the file listing is empty'], StaleSeedWordingCheck::violations([], static fn(string $path): string => ''));
    }

    #[Test]
    public function a_listing_missing_a_site_file_fails_closed(): void
    {
        $root = \base_path();
        $paths = \array_values(\array_filter(TrackedFiles::list($root), static fn(string $path): bool => $path !== 'database/AGENTS.md'));

        $this->assertSame(
            [
                'site file missing from the listing: database/AGENTS.md',
                'doc file missing from the listing: database/AGENTS.md',
            ],
            StaleSeedWordingCheck::violations($paths, TrackedFiles::reader($root)),
        );
    }
}
