<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

use Symfony\Component\Process\Process;

/**
 * A main checkout and a linked worktree holding the real Makefile, bin/worktree-db and bin/stack, run under `env -i` with a
 * stub-only PATH: `docker` answers for the worktree's Postgres while it is up, and `php` logs the connection Laravel would
 * build (a process-env key, even blank, over the checkout's .env). Every .env names the main database, 127.0.0.1:54322.
 * `composer`, `pnpm` and `psql` log to tool-calls; bin/install-git-hooks is the real one, writing the fixture's own hooks.
 */
final readonly class WorktreeDbFixture
{
    public const int PORT = 55001;

    public const string PASSWORD = 'stub-worktree-password';

    public const string MAIN_CONNECTION = 'host=127.0.0.1 port=54322 user=postgres password=postgres url=';

    public const string OWN_CONNECTION = 'host=127.0.0.1 port=' . self::PORT . ' user=postgres password=' . self::PASSWORD . ' url=';

    public const string ENV = "APP_ENV=local\nDB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=54322\nDB_DATABASE=postgres\nDB_USERNAME=postgres\nDB_PASSWORD=postgres\nDB_URL=\n";

    private const string DOCKER = <<<'SH'
        #!/bin/sh
        S="$(cd "$(dirname "$0")/.." && pwd -P)"
        echo "$*" >>"$S/docker-calls"
        [ -f "$S/db-up" ] || { [ "$1" = compose ] && exit 1; exit 0; }
        case "$1" in
            ps) echo stub-container ;;
            inspect) case "$3" in *ExitCode*) echo 'exited 0' ;; *) echo running ;; esac ;;
            compose) case " $* " in *' port pgsql 5432 '*) echo "127.0.0.1:$(cat "$S/db-up")" ;; esac ;;
        esac
        exit 0
        SH;

    private const string PHP = <<<'SH'
        #!/bin/sh
        S="$(cd "$(dirname "$0")/.." && pwd -P)"
        val() {
            if eval "[ -n \"\${$1+x}\" ]"; then eval "printf '%s' \"\$$1\""
            elif [ -f .env ]; then sed -n "s/^$1=//p" .env | tail -1 | tr -d '\n'; fi
        }
        echo "$* | host=$(val DB_HOST) port=$(val DB_PORT) user=$(val DB_USERNAME) password=$(val DB_PASSWORD) url=$(val DB_URL)" >>"$S/php-calls"
        exit 0
        SH;

    private const string LOGGER = <<<'SH'
        #!/bin/sh
        S="$(cd "$(dirname "$0")/.." && pwd -P)"
        echo "$(basename "$0") $*" >>"$S/tool-calls"
        exit 0
        SH;

    private const string CHANGED_TESTS = <<<'SH'
        #!/bin/sh
        # intentional: stands in for bin/changed-tests, which needs origin/HEAD; it selects one test.
        [ "$1" = --phpunit-config ] && echo '<phpunit/>' >"$2"
        SH;

    public string $root;

    public string $main;

    public string $worktree;

    public string $stubs;

    public function __construct()
    {
        $root = \sys_get_temp_dir() . '/worktree-db-' . \bin2hex(\random_bytes(6));
        \mkdir($root, 0o700, true);
        $this->root = (string) \realpath($root);
        $this->main = "{$this->root}/main";
        $this->worktree = "{$this->root}/wt";
        $this->stubs = "{$this->root}/bin";

        foreach (['bin', 'home', 'main/bin'] as $dir) {
            \mkdir("{$this->root}/{$dir}", 0o700, true);
        }

        foreach (['docker' => self::DOCKER, 'php' => self::PHP, 'composer' => self::LOGGER, 'pnpm' => self::LOGGER, 'psql' => self::LOGGER] as $name => $script) {
            $this->script("{$this->stubs}/{$name}", $script);
        }

        $repo = \dirname(__DIR__, 4);

        foreach (['Makefile', 'bin/worktree-db', 'bin/stack', 'bin/install-env', 'bin/install-git-hooks'] as $file) {
            \copy("{$repo}/{$file}", "{$this->main}/{$file}");
            \chmod("{$this->main}/{$file}", (int) \fileperms("{$repo}/{$file}") & 0o777);
        }

        $this->script("{$this->main}/bin/changed-tests", self::CHANGED_TESTS);
        $this->git($this->main, 'init', '-q');
        $this->git($this->main, 'add', 'Makefile', 'bin');
        $this->git($this->main, '-c', 'user.name=t', '-c', 'user.email=t@example.test', '-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'fixture');
        $this->git($this->main, 'worktree', 'add', '-q', $this->worktree, '-b', 'wt');

        foreach ([$this->main, $this->worktree] as $checkout) {
            \file_put_contents("{$checkout}/.env", self::ENV);
        }

        \mkdir("{$this->worktree}/tmp/stack", 0o700, true);
        \file_put_contents("{$this->worktree}/tmp/stack/compose.env", "STACK_PROJECT=stub\nSTACK_DB_PASSWORD=" . self::PASSWORD . "\n");
    }

    public function remove(): void
    {
        if ((new Process(['rm', '-rf', $this->root]))->run() === 0) {
            return;
        }

        // intentional: under load a straggling write can refill a directory mid-delete ("Directory not empty"); one retry clears it.
        \usleep(200_000);
        (new Process(['rm', '-rf', $this->root]))->mustRun();
    }

    public function dbUp(int $port = self::PORT): void
    {
        \file_put_contents("{$this->root}/db-up", (string) $port);
    }

    /**
     * @param array<string, string> $env
     */
    public function run(string $cwd, array $env, string ...$command): Process
    {
        $pairs = ["PATH={$this->stubs}:/usr/bin:/bin", "HOME={$this->root}/home"];

        foreach ($env as $key => $value) {
            $pairs[] = "{$key}={$value}";
        }

        $process = new Process(['/usr/bin/env', '-i', ...$pairs, ...\array_values($command)], $cwd);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    /**
     * @param array<string, string> $env
     */
    public function make(string $cwd, array $env, string $target): Process
    {
        return $this->run($cwd, $env, 'make', '--no-print-directory', $target, "EXEC={$this->stubs}/php", "ALZ_ADMIN={$this->root}/home");
    }

    /**
     * @return list<string> each php call's connection, as host=… port=… user=… password=… url=…
     */
    public function connections(): array
    {
        return \array_map(static fn(string $call): string => \explode(' | ', $call, 2)[1] ?? '', $this->calls('php-calls'));
    }

    /**
     * @return list<string>
     */
    public function calls(string $log): array
    {
        $file = "{$this->root}/{$log}";

        return \is_file($file) ? \explode("\n", \mb_rtrim((string) \file_get_contents($file), "\n")) : [];
    }

    public function clearCalls(): void
    {
        foreach (['php-calls', 'docker-calls', 'tool-calls'] as $log) {
            (new Process(['rm', '-f', "{$this->root}/{$log}"]))->mustRun();
        }
    }

    private function script(string $path, string $contents): void
    {
        \file_put_contents($path, $contents);
        \chmod($path, 0o755);
    }

    private function git(string $cwd, string ...$arguments): void
    {
        (new Process(['git', ...\array_values($arguments)], $cwd, ['HOME' => "{$this->root}/home", ...GitHooksInstalledCheck::cleanGitEnv()]))->mustRun();
    }
}
