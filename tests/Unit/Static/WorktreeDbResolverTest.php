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
 * bin/worktree-db's steps, in a fixture main checkout and linked worktree whose .env names the main database (54322).
 */
#[CoversNothing]
#[Group('repo-static')]
final class WorktreeDbResolverTest extends TestCase
{
    private const string NO_DB = 'no database for this worktree: run make db-up (or make serve)';

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
    public function is_worktree_tells_a_linked_worktree_from_the_main_checkout(): void
    {
        $worktree = $this->resolve($this->fixture->worktree, [], '--is-worktree');
        $main = $this->resolve($this->fixture->main, [], '--is-worktree');

        $this->assertSame([0, "1\n"], [$worktree->getExitCode(), $worktree->getOutput()]);
        $this->assertSame([0, "0\n"], [$main->getExitCode(), $main->getOutput()]);
        $this->assertSame([], $this->fixture->calls('docker-calls'), 'the worktree test asks git alone');
    }

    #[Test]
    public function a_worktree_command_gets_the_worktree_postgres(): void
    {
        $this->fixture->dbUp();

        $run = $this->resolve($this->fixture->worktree, [], 'php', 'artisan', 'migrate');

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $this->assertSame([WorktreeDbFixture::OWN_CONNECTION], $this->fixture->connections());
        $this->assertSame(['artisan migrate | ' . WorktreeDbFixture::OWN_CONNECTION], $this->fixture->calls('php-calls'));
    }

    #[Test]
    public function a_worktree_without_its_postgres_refuses_before_running_anything(): void
    {
        $run = $this->resolve($this->fixture->worktree, [], 'php', 'artisan', 'migrate');

        $this->assertSame(75, $run->getExitCode());
        $this->assertSame('bin/worktree-db: ' . self::NO_DB . "\n", $run->getErrorOutput());
        $this->assertSame([], $this->fixture->calls('php-calls'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function passThroughs(): iterable
    {
        yield 'a caller DB_HOST' => [['DB_HOST' => '10.0.0.7'], 'host=10.0.0.7 port=54322 user=postgres password=postgres url='];
        yield 'a caller DB_PORT' => [['DB_PORT' => '6543'], 'host=127.0.0.1 port=6543 user=postgres password=postgres url='];
        yield 'a caller DB_URL' => [['DB_URL' => 'postgres://u:p@10.0.0.7:6543/db'], 'host=127.0.0.1 port=54322 user=postgres password=postgres url=postgres://u:p@10.0.0.7:6543/db'];
        yield 'a blank caller DB_URL' => [['DB_URL' => ''], WorktreeDbFixture::MAIN_CONNECTION];
        yield 'CI=true' => [['CI' => 'true'], WorktreeDbFixture::MAIN_CONNECTION];
    }

    /**
     * @param array<string, string> $env
     */
    #[Test]
    #[DataProvider('passThroughs')]
    public function a_caller_connection_ci_or_container_run_passes_through_unchanged(array $env, string $connection): void
    {
        $run = $this->resolve($this->fixture->worktree, $env, 'php', 'artisan', 'migrate');

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $this->assertSame([$connection], $this->fixture->connections());
        $this->assertSame([], $this->fixture->calls('docker-calls'), 'a pass-through never asks the stack');
    }

    #[Test]
    public function a_container_run_passes_through_unchanged_without_git(): void
    {
        $dir = "{$this->fixture->root}/no-git";
        \mkdir("{$dir}/bin", 0o700, true);
        \copy("{$this->fixture->main}/bin/worktree-db", "{$dir}/bin/worktree-db");
        \chmod("{$dir}/bin/worktree-db", 0o755);
        \file_put_contents("{$this->fixture->root}/dockerenv", '');

        $run = $this->fixture->run($dir, ['WORKTREE_DB_DOCKERENV' => "{$this->fixture->root}/dockerenv"], 'bin/worktree-db', 'php', 'vendor/bin/pest');

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $this->assertSame(['vendor/bin/pest | host= port= user= password= url='], $this->fixture->calls('php-calls'));
    }

    #[Test]
    public function the_main_checkout_keeps_its_env(): void
    {
        $this->fixture->dbUp();

        $run = $this->resolve($this->fixture->main, [], 'php', 'artisan', 'migrate');

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $this->assertSame([WorktreeDbFixture::MAIN_CONNECTION], $this->fixture->connections());
        $this->assertSame([], $this->fixture->calls('docker-calls'));
    }

    #[Test]
    public function a_git_failure_refuses(): void
    {
        $dir = "{$this->fixture->root}/no-git";
        \mkdir("{$dir}/bin", 0o700, true);
        \copy("{$this->fixture->main}/bin/worktree-db", "{$dir}/bin/worktree-db");
        \chmod("{$dir}/bin/worktree-db", 0o755);

        foreach ([['php', 'artisan', 'migrate'], ['--is-worktree']] as $arguments) {
            $run = $this->fixture->run($dir, [], 'bin/worktree-db', ...$arguments);

            $this->assertSame(75, $run->getExitCode(), \implode(' ', $arguments));
            $this->assertStringContainsString('git rev-parse failed', $run->getErrorOutput());
        }

        $this->assertSame([], $this->fixture->calls('php-calls'));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function usages(): iterable
    {
        yield 'no command' => [[]];
        yield 'only --destructive' => [['--destructive']];
        yield '--is-worktree with a command' => [['--is-worktree', 'php']];
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('usages')]
    public function it_refuses_a_bad_usage(array $arguments): void
    {
        $run = $this->resolve($this->fixture->worktree, [], ...$arguments);

        $this->assertSame(64, $run->getExitCode());
        $this->assertStringContainsString('usage: bin/worktree-db', $run->getErrorOutput());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function destructiveRefusals(): iterable
    {
        yield 'a caller DB_HOST with the .env 54322' => [['DB_HOST' => '127.0.0.1'], "its database port '54322' is not this worktree's Postgres"];
        yield 'a caller non-loopback DB_HOST' => [['DB_HOST' => '10.0.0.7', 'DB_PORT' => (string) WorktreeDbFixture::PORT], "its database host '10.0.0.7' is not loopback"];
        yield 'a portless DB_URL' => [['DB_URL' => 'postgres://postgres:postgres@127.0.0.1/postgres'], "its database port '5432' is not this worktree's Postgres"];
        yield 'a DB_URL on 54322' => [['DB_URL' => 'postgres://postgres:postgres@localhost:54322/postgres', 'DB_PORT' => (string) WorktreeDbFixture::PORT], "its database port '54322' is not this worktree's Postgres"];
        yield 'CI=true with the .env 54322' => [['CI' => 'true'], "its database port '54322' is not this worktree's Postgres"];
    }

    /**
     * @param array<string, string> $env
     */
    #[Test]
    #[DataProvider('destructiveRefusals')]
    public function a_destructive_run_refuses_any_connection_but_the_worktree_postgres(array $env, string $reason): void
    {
        $this->fixture->dbUp();

        $run = $this->resolve($this->fixture->worktree, $env, '--destructive', 'php', 'artisan', 'dev:seed-fake');

        $this->assertSame(75, $run->getExitCode());
        $this->assertStringContainsString("refusing a destructive run: {$reason}", $run->getErrorOutput());
        $this->assertSame([], $this->fixture->calls('php-calls'));
    }

    #[Test]
    public function a_destructive_container_run_in_a_worktree_still_refuses_the_env_database(): void
    {
        $this->fixture->dbUp();
        \file_put_contents("{$this->fixture->root}/dockerenv", '');

        $run = $this->resolve($this->fixture->worktree, ['WORKTREE_DB_DOCKERENV' => "{$this->fixture->root}/dockerenv"], '--destructive', 'php', 'artisan', 'dev:seed-fake');

        $this->assertSame(75, $run->getExitCode());
        $this->assertStringContainsString("its database port '54322' is not this worktree's Postgres", $run->getErrorOutput());
        $this->assertSame([], $this->fixture->calls('php-calls'));
    }

    #[Test]
    public function a_destructive_run_without_the_worktree_postgres_refuses(): void
    {
        $run = $this->resolve($this->fixture->worktree, ['DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) WorktreeDbFixture::PORT], '--destructive', 'php', 'artisan', 'dev:seed-fake');

        $this->assertSame(75, $run->getExitCode());
        $this->assertSame('bin/worktree-db: refusing a destructive run: ' . self::NO_DB . "\n", $run->getErrorOutput());
        $this->assertSame([], $this->fixture->calls('php-calls'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function destructiveRuns(): iterable
    {
        $own = (string) WorktreeDbFixture::PORT;

        yield 'no caller keys' => [[], WorktreeDbFixture::OWN_CONNECTION];
        yield 'the caller names the worktree postgres' => [['DB_HOST' => 'localhost', 'DB_PORT' => $own], "host=localhost port={$own} user=postgres password=postgres url="];
        yield 'a DB_URL on the worktree port' => [['DB_URL' => "postgres://postgres:x@127.0.0.1:{$own}/postgres"], "host=127.0.0.1 port=54322 user=postgres password=postgres url=postgres://postgres:x@127.0.0.1:{$own}/postgres"];
    }

    /**
     * @param array<string, string> $env
     */
    #[Test]
    #[DataProvider('destructiveRuns')]
    public function a_destructive_run_on_the_worktree_postgres_runs(array $env, string $connection): void
    {
        $this->fixture->dbUp();

        $run = $this->resolve($this->fixture->worktree, $env, '--destructive', 'php', 'artisan', 'dev:seed-fake');

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $this->assertSame([$connection], $this->fixture->connections());
    }

    #[Test]
    public function a_destructive_run_in_the_main_checkout_keeps_its_env(): void
    {
        $run = $this->resolve($this->fixture->main, [], '--destructive', 'php', 'artisan', 'dev:seed-fake');

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $this->assertSame([WorktreeDbFixture::MAIN_CONNECTION], $this->fixture->connections());
    }

    /**
     * @param array<string, string> $env
     */
    private function resolve(string $cwd, array $env, string ...$arguments): Process
    {
        return $this->fixture->run($cwd, $env, 'bin/worktree-db', ...$arguments);
    }
}
