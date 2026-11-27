<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
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

    /** Targets that branch on bin/worktree-db --is-worktree instead (S3). */
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
        $this->assertSame(['seed-fake'], \array_keys(\array_filter($recipes, static fn(string $recipe): bool => \str_contains($recipe, '$(DB_RUN) --destructive'))));
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
