<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Static\Support\WorktreeDbFixture;

/**
 * Every Makefile target that opens the database runs through bin/worktree-db: real `make` in a fixture main checkout and
 * linked worktree, under `env -i` with stub tools, with each .env naming the main database (127.0.0.1:54322).
 */
#[CoversNothing]
#[Group('repo-static')]
final class MakefileDbTargetsTest extends TestCase
{
    /** Targets whose own recipe opens the database through $(DB_RUN), with the number of DB-opening commands each runs. */
    private const array DB_TARGETS = [
        'test' => 1, 'test-changed' => 1, 'test-integration' => 1, 'test-seed-command' => 1, 'test-redis' => 1,
        'test-quickbooks-sandbox' => 1, 'test-app' => 1, 'test-app-coverage' => 1, 'test-coverage-ci' => 1,
        'coverage-html' => 1, 'pest-mutate' => 1, 'mutate-app' => 1, 'migrate' => 1, 'seed-fake' => 1, 'ide-helper' => 3,
    ];

    /** Targets that reach the database only through a recursive make of DB_TARGETS, with each run's command count. */
    private const array RECURSIVE_TARGETS = ['test-ai' => 2, 'test-mutate' => 2, 'test-coverage' => 1];

    /** Targets whose php commands open no database. */
    private const array NO_DB_TARGETS = [
        'lint', 'lint-sequential', 'lint-full', 'git-hooks-check', 'test-quick', 'test-domain', 'test-domain-coverage',
        'mutate-domain', 'test-docker', 'pail',
    ];

    /** Targets that branch on bin/worktree-db --is-worktree instead. */
    private const array BRANCHING_TARGETS = ['install', 'db-reset-full'];

    private WorktreeDbFixture $fixture;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new WorktreeDbFixture();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->fixture->remove();

        parent::tearDown();
    }

    #[Test]
    public function every_target_that_runs_pest_or_artisan_is_classified(): void
    {
        $recipes = $this->recipes();
        $opening = \array_keys(\array_filter($recipes, static fn(string $recipe): bool => (bool) \preg_match('#vendor/bin/pest|\bartisan\b#', $recipe)));
        $classified = [...\array_keys(self::DB_TARGETS), ...self::NO_DB_TARGETS, ...self::BRANCHING_TARGETS];

        $this->assertSame([], \array_values(\array_diff($opening, $classified)), 'a target runs Pest or artisan but is in no list');

        foreach (\array_keys(self::DB_TARGETS) as $target) {
            $this->assertMatchesRegularExpression('#\$\(DB_RUN\)( --destructive)? \$\(EXEC\)#', $recipes[$target], $target);
            $this->assertDoesNotMatchRegularExpression('#(?<!\$\(DB_RUN\) |--destructive )\$\(EXEC\)#', $recipes[$target], "{$target} has an unwrapped \$(EXEC)");
        }

        foreach ([...self::NO_DB_TARGETS, ...\array_keys(self::RECURSIVE_TARGETS)] as $target) {
            $this->assertStringNotContainsString('$(DB_RUN)', $recipes[$target], $target);
        }

        $this->assertStringContainsString('$(DB_RUN) --destructive $(EXEC) artisan dev:seed-fake', $recipes['seed-fake']);
        $this->assertSame(['db-reset-full', 'seed-fake'], \array_keys(\array_filter($recipes, static fn(string $recipe): bool => \str_contains($recipe, '$(DB_RUN) --destructive'))));

        foreach (self::BRANCHING_TARGETS as $target) {
            $this->assertStringContainsString('$(IN_WORKTREE)', $recipes[$target], $target);
        }
    }

    #[Test]
    public function a_fresh_worktree_install_touches_no_database_and_closes_the_env_db_keys(): void
    {
        \unlink("{$this->fixture->worktree}/.env");

        $make = $this->fixture->make($this->fixture->worktree, [], 'install');

        $this->assertSame(0, $make->getExitCode(), $make->getOutput() . $make->getErrorOutput());
        $this->assertSame(['composer install'], $this->fixture->calls('tool-calls'), 'no pnpm (Supabase) or psql call');
        $this->assertSame(['artisan key:generate'], $this->phpCommands(), 'no migrate');
        $this->assertSame([], $this->composeCalls(), 'no Redis or stack start');
        $this->assertSame(
            "APP_ENV=local\nDB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=9\nDB_DATABASE=postgres\nDB_USERNAME=postgres\nDB_PASSWORD=postgres\nDB_URL=\nSHARED_DB_PORT=54322\n",
            \file_get_contents("{$this->fixture->worktree}/.env"),
        );
        $this->assertSame(WorktreeDbFixture::ENV, \file_get_contents("{$this->fixture->main}/.env"), 'the main checkout .env is untouched');
    }

    #[Test]
    public function a_worktree_install_rewrites_only_the_db_keys_of_an_existing_env(): void
    {
        $env = "APP_KEY=base64:kept\nexport DB_HOST=db.local\nDB_PORT=6000\nFOO=bar\nDB_URL=postgres://u:p@db.local:6000/x\nDB_PORT=6001\n# DB_PORT=1 comment\n";
        \file_put_contents("{$this->fixture->worktree}/.env", $env);

        foreach ([1, 2] as $run) {
            $make = $this->fixture->make($this->fixture->worktree, [], 'install');

            $this->assertSame(0, $make->getExitCode(), "install run {$run}: " . $make->getErrorOutput());
            $this->assertSame(
                "APP_KEY=base64:kept\nDB_HOST=127.0.0.1\nDB_PORT=9\nFOO=bar\nDB_URL=\n# DB_PORT=1 comment\nSHARED_DB_PORT=6001\n",
                \file_get_contents("{$this->fixture->worktree}/.env"),
                "install run {$run} keeps the shared port and every other line",
            );
        }

        $this->assertSame([], $this->phpCommands(), 'APP_KEY kept, no migrate');
        $this->assertSame([], $this->composeCalls());
    }

    #[Test]
    public function a_main_checkout_install_still_starts_supabase_redis_and_migrates(): void
    {
        $this->fixture->dbUp();

        $make = $this->fixture->make($this->fixture->main, [], 'install');

        $this->assertSame(0, $make->getExitCode(), $make->getOutput() . $make->getErrorOutput());
        $this->assertSame(WorktreeDbFixture::ENV, \file_get_contents("{$this->fixture->main}/.env"));
        $this->assertContains('pnpm exec supabase status', $this->fixture->calls('tool-calls'));
        $this->assertContains('compose up -d redis', $this->fixture->calls('docker-calls'));
        $this->assertSame(['artisan key:generate | ' . WorktreeDbFixture::MAIN_CONNECTION, 'artisan migrate | ' . WorktreeDbFixture::MAIN_CONNECTION], $this->fixture->calls('php-calls'));
    }

    #[Test]
    public function a_worktree_install_needs_no_alz_admin(): void
    {
        $make = $this->fixture->run($this->fixture->worktree, [], 'make', '--no-print-directory', 'install', "EXEC={$this->fixture->stubs}/php");

        $this->assertSame(0, $make->getExitCode(), $make->getOutput() . $make->getErrorOutput());
        $this->assertStringNotContainsString('ALZ_ADMIN', $make->getErrorOutput());
        $this->assertSame(['composer install'], $this->fixture->calls('tool-calls'), 'no pnpm or supabase call');
    }

    #[Test]
    public function a_main_checkout_install_without_alz_admin_refuses_before_any_step(): void
    {
        $make = $this->fixture->run($this->fixture->main, [], 'make', '--no-print-directory', 'install', "EXEC={$this->fixture->stubs}/php");

        $this->assertNotSame(0, $make->getExitCode());
        $this->assertStringContainsString('ALZ_ADMIN not set', $make->getErrorOutput());
        $this->assertSame([], $this->fixture->calls('tool-calls'));
    }

    #[Test]
    public function a_worktree_db_reset_full_recreates_its_own_postgres_and_seeds_it(): void
    {
        $this->fixture->dbUp();

        $make = $this->fixture->make($this->fixture->worktree, [], 'db-reset-full');

        $this->assertSame(0, $make->getExitCode(), $make->getOutput() . $make->getErrorOutput());
        $this->assertSame([], $this->fixture->calls('tool-calls'), 'no supabase-reset (pnpm db:setup-local)');
        $this->assertSame(
            ['artisan dev:seed-fake --dry-run | ' . WorktreeDbFixture::OWN_CONNECTION, 'artisan dev:seed-fake | ' . WorktreeDbFixture::OWN_CONNECTION],
            $this->fixture->calls('php-calls'),
        );
        $this->assertNotSame([], \preg_grep('/ up .*--force-recreate pgsql$/', $this->fixture->calls('docker-calls')), 'bin/stack db-reset recreated pgsql');
        $this->assertStringContainsString('db-reset ok', $make->getOutput());
    }

    #[Test]
    public function a_worktree_db_reset_full_without_its_postgres_refuses_before_resetting(): void
    {
        $make = $this->fixture->make($this->fixture->worktree, [], 'db-reset-full');

        $this->assertNotSame(0, $make->getExitCode());
        $this->assertStringContainsString('refusing a destructive run: no database for this worktree: run make db-up', $make->getErrorOutput());
        $this->assertSame([], $this->fixture->calls('php-calls'));
        $this->assertSame([], $this->fixture->calls('tool-calls'));
        $this->assertSame([], \preg_grep('/ up /', $this->composeCalls()));
    }

    #[Test]
    public function a_main_checkout_db_reset_full_still_resets_supabase(): void
    {
        $make = $this->fixture->make($this->fixture->main, [], 'db-reset-full');

        $this->assertSame(0, $make->getExitCode(), $make->getOutput() . $make->getErrorOutput());
        $this->assertSame(['pnpm db:setup-local'], $this->fixture->calls('tool-calls'));
        $this->assertSame(['artisan dev:seed-fake --dry-run', 'artisan dev:seed-fake'], $this->phpCommands());
        $this->assertSame([WorktreeDbFixture::MAIN_CONNECTION, WorktreeDbFixture::MAIN_CONNECTION], $this->fixture->connections());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function supabaseTargets(): iterable
    {
        foreach (['supabase-start', 'supabase-reset', 'supabase-seed-users', 'supabase-stop'] as $target) {
            yield $target => [$target];
        }
    }

    #[Test]
    #[DataProvider('supabaseTargets')]
    public function a_worktree_supabase_target_refuses_with_75(string $target): void
    {
        $make = $this->fixture->make($this->fixture->worktree, [], $target);

        $this->assertNotSame(0, $make->getExitCode());
        $this->assertStringContainsString("make {$target} acts on the shared Supabase; run it in the main checkout", $make->getErrorOutput());
        $this->assertMakeError(75, $target, $make, 'the refusing recipe line exits 75');
        $this->assertSame([], $this->fixture->calls('tool-calls'), 'no pnpm call');
        $this->assertSame([], $this->fixture->calls('php-calls'));
    }

    #[Test]
    #[DataProvider('supabaseTargets')]
    public function a_worktree_supabase_target_without_alz_admin_still_refuses_with_75(string $target): void
    {
        $make = $this->fixture->run($this->fixture->worktree, [], 'make', '--no-print-directory', $target, "EXEC={$this->fixture->stubs}/php");

        $this->assertMakeError(75, $target, $make);
        $this->assertStringNotContainsString('ALZ_ADMIN not set', $make->getErrorOutput());
        $this->assertSame([], $this->fixture->calls('tool-calls'), 'no pnpm call');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function worktreeCheckTargets(): iterable
    {
        yield from self::supabaseTargets();

        foreach (['install', 'db-reset-full'] as $target) {
            yield $target => [$target];
        }
    }

    #[Test]
    #[DataProvider('worktreeCheckTargets')]
    public function a_failed_worktree_check_refuses_instead_of_acting_on_the_shared_supabase(string $target): void
    {
        $make = $this->fixture->make($this->fixture->main, ['GIT_DIR' => "{$this->fixture->main}/no-such-git-dir"], $target);

        $this->assertMakeError(75, $target, $make);
        $this->assertSame([], $this->fixture->calls('tool-calls'), 'no pnpm or composer call');
        $this->assertSame([], $this->fixture->calls('php-calls'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function alzAdminTargets(): iterable
    {
        foreach (['supabase-start', 'supabase-reset', 'supabase-seed-users'] as $target) {
            yield $target => [$target];
        }
    }

    #[Test]
    #[DataProvider('alzAdminTargets')]
    public function a_main_checkout_supabase_target_without_alz_admin_refuses(string $target): void
    {
        $make = $this->fixture->run($this->fixture->main, [], 'make', '--no-print-directory', $target, "EXEC={$this->fixture->stubs}/php");

        $this->assertMakeError(1, $target, $make);
        $this->assertStringContainsString('ALZ_ADMIN not set', $make->getErrorOutput());
        $this->assertSame([], $this->fixture->calls('tool-calls'), 'no pnpm call');
    }

    #[Test]
    #[DataProvider('supabaseTargets')]
    public function a_main_checkout_supabase_target_still_runs(string $target): void
    {
        $make = $this->fixture->make($this->fixture->main, [], $target);

        $this->assertSame(0, $make->getExitCode(), $make->getOutput() . $make->getErrorOutput());
        $this->assertNotSame([], \preg_grep('/^pnpm /', $this->fixture->calls('tool-calls')));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function dbTargets(): iterable
    {
        foreach ([...self::DB_TARGETS, ...self::RECURSIVE_TARGETS] as $target => $calls) {
            yield $target => [$target, $calls];
        }
    }

    #[Test]
    #[DataProvider('dbTargets')]
    public function a_worktree_target_resolves_to_the_worktree_postgres(string $target, int $calls): void
    {
        $this->fixture->dbUp();

        $make = $this->fixture->make($this->fixture->worktree, [], $target);

        $this->assertSame(0, $make->getExitCode(), $make->getOutput() . $make->getErrorOutput());
        $this->assertSame(\array_fill(0, $calls, WorktreeDbFixture::OWN_CONNECTION), $this->dbConnections());
    }

    #[Test]
    #[DataProvider('dbTargets')]
    public function a_main_checkout_target_resolves_to_its_env(string $target, int $calls): void
    {
        $this->fixture->dbUp();

        $make = $this->fixture->make($this->fixture->main, [], $target);

        $this->assertSame(0, $make->getExitCode(), $make->getOutput() . $make->getErrorOutput());
        $this->assertSame(\array_fill(0, $calls, WorktreeDbFixture::MAIN_CONNECTION), $this->dbConnections());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusingTargets(): iterable
    {
        foreach (\array_keys([...self::DB_TARGETS, ...self::RECURSIVE_TARGETS]) as $target) {
            yield $target => [$target];
        }
    }

    #[Test]
    #[DataProvider('refusingTargets')]
    public function a_worktree_target_without_its_postgres_refuses_naming_the_db_only_start(string $target): void
    {
        $make = $this->fixture->make($this->fixture->worktree, [], $target);

        $this->assertNotSame(0, $make->getExitCode());
        $this->assertStringContainsString('run make db-up', $make->getErrorOutput());
        $this->assertSame([], $this->dbConnections(), 'nothing that opens the database ran');
    }

    #[Test]
    public function migrate_with_a_caller_host_and_port_connects_there(): void
    {
        $this->fixture->dbUp();

        $make = $this->fixture->make($this->fixture->worktree, ['DB_HOST' => '10.0.0.7', 'DB_PORT' => '6543'], 'migrate');

        $this->assertSame(0, $make->getExitCode(), $make->getErrorOutput());
        $this->assertSame(['artisan migrate | host=10.0.0.7 port=6543 user=postgres password=postgres url='], $this->fixture->calls('php-calls'));
    }

    #[Test]
    public function a_ci_run_in_a_worktree_keeps_its_env(): void
    {
        $make = $this->fixture->make($this->fixture->worktree, ['CI' => 'true'], 'test');

        $this->assertSame(0, $make->getExitCode(), $make->getErrorOutput());
        $this->assertSame([WorktreeDbFixture::MAIN_CONNECTION], $this->dbConnections());
    }

    #[Test]
    public function a_worktree_seed_refuses_a_caller_host_on_the_main_port(): void
    {
        $this->fixture->dbUp();

        $make = $this->fixture->make($this->fixture->worktree, ['DB_HOST' => '127.0.0.1'], 'seed-fake');

        $this->assertNotSame(0, $make->getExitCode());
        $this->assertStringContainsString("refusing a destructive run: its database port '54322'", $make->getErrorOutput());
        $this->assertSame([], $this->fixture->calls('php-calls'));
    }

    /**
     * @return list<string> the connection of each php call except the Domain-only ones a recursive make also runs
     */
    private function dbConnections(): array
    {
        $calls = \array_filter(
            $this->fixture->calls('php-calls'),
            static fn(string $call): bool => \preg_match('/--testsuite=Domain|phpunit-domain\.xml/', \explode(' | ', $call, 2)[0]) !== 1,
        );

        return \array_values(\array_map(static fn(string $call): string => \explode(' | ', $call, 2)[1] ?? '', $calls));
    }

    /**
     * GNU Make 3.81 prints `[target] Error N`, 4.x `[Makefile:LINE: target] Error N`.
     */
    private function assertMakeError(int $code, string $target, Process $make, string $message = ''): void
    {
        $this->assertMatchesRegularExpression('/\[(?:\S+:\d+: )?' . \preg_quote($target, '/') . "\\] Error {$code}\\b/", $make->getErrorOutput(), $message);
    }

    /**
     * @return list<string> the docker compose calls; the Makefile's mode check runs `docker info` on every make
     */
    private function composeCalls(): array
    {
        return \array_values(\preg_grep('/^compose /', $this->fixture->calls('docker-calls')) ?: []);
    }

    /**
     * @return list<string> each php call's command, without its connection
     */
    private function phpCommands(): array
    {
        return \array_map(static fn(string $call): string => \explode(' | ', $call, 2)[0], $this->fixture->calls('php-calls'));
    }

    /**
     * @return array<string, string> each target's recipe lines, joined
     */
    private function recipes(): array
    {
        $recipes = [];
        $target = null;

        foreach (\explode("\n", (string) \file_get_contents(\dirname(__DIR__, 3) . '/Makefile')) as $line) {
            if (\preg_match('/^([a-z][a-z0-9-]*):(?!=)/', $line, $match) === 1) {
                $target = $match[1];
                $recipes[$target] = '';
            } elseif ($target !== null && \str_starts_with($line, "\t")) {
                $recipes[$target] .= $line . "\n";
            } elseif ($line !== '' && !\str_starts_with($line, '#')) {
                $target = null;
            }
        }

        return $recipes;
    }
}
