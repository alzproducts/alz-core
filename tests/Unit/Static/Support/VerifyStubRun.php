<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The verify entry point against a fixture git repo, with a `docker` stub first on PATH that records each call
 * and keeps its labelled resources as files, one directory per kind.
 */
final readonly class VerifyStubRun
{
    public const array KINDS = ['container', 'network', 'volume', 'image'];

    private const string STUB = <<<'SH'
        #!/bin/sh
        # resources/<kind>/<project>__<name> is one labelled resource; knobs/<name> scripts an answer.
        S="$(cd "$(dirname "$0")/.." && pwd -P)"
        echo "$@" >>"$S/docker-calls"
        knob() { cat "$S/knobs/$1" 2>/dev/null; }
        list() {
            kind="$1"; shift; project=''; service=''
            for a in "$@"; do
                case "$a" in
                    label=com.docker.compose.project=*) project="${a#label=com.docker.compose.project=}" ;;
                    label=com.docker.compose.service=*) service="${a#label=com.docker.compose.service=}" ;;
                esac
            done
            for f in "$S/resources/$kind/${project}__${service:-}"*; do
                [ -e "$f" ] && basename "$f"
            done
            exit 0
        }
        remove() { kind="$1"; shift; for a in "$@"; do case "$a" in -*) ;; *) rm -f "$S/resources/$kind/$a" ;; esac; done; exit 0; }
        case "$1 $2" in
            "info "*) exit 0 ;;
            "ps "*) list container "$@" ;;
            "rm "*) shift; remove container "$@" ;;
            "network ls" | "volume ls" | "image ls") list "$@" ;;
            "network rm" | "volume rm" | "image rm") k="$1"; shift 2; remove "$k" "$@" ;;
            "inspect "*) knob "state-${4##*__}" || echo 'exited 0'; exit 0 ;;
        esac
        [ "$1" = compose ] || exit 1
        project="$3"; shift 3
        while :; do
            case "$1" in
                -f | --project-directory | --profile | --env-file | --progress) shift 2 ;;
                *) break ;;
            esac
        done
        verb="$1"; shift
        case "$verb" in
            build)
                : >"$S/resources/image/${project}__$1"
                [ "$(knob hang)" = build ] && echo $$ >"$S/hung-build" && exec sleep 120
                [ "$(knob fail)" = build ] && exit 1
                ;;
            up)
                for s in app worker seed auth-migrate; do : >"$S/resources/container/${project}__$s"; done
                : >"$S/resources/network/${project}__default"
                : >"$S/resources/volume/${project}__data"
                [ "$(knob fail)" = up ] && exit 1
                ;;
            logs) knob seed-log ;;
            port)
                [ "$(knob fail)" = port ] && exit 1
                echo 127.0.0.1:5000
                ;;
            exec)
                shift 2
                if [ "$1" = php ]; then exec "$@"; fi
                cat "$S/responses/$project"
                ;;
        esac
        exit 0
        SH;

    private const array FILES = [
        'Dockerfile.verify' => "FROM scratch\n",
        'docker-entrypoint.sh' => "php artisan db:ping\n",
        'database/baseline/versions.env' => "SUPABASE_POSTGRES_VERSION=1\nSUPABASE_AUTH_VERSION=1\n",
        'database/seeders/Seeder.php' => "one\n",
        'database/factories/Factory.php' => "one\n",
        'database/seed-profile/profile.json' => "one\n",
        'app/Presentation/Console/Commands/Dev/SeedFakeDataCommand.php' => "one\n",
        'composer.lock' => '{"packages": [], "packages-dev": [{"name": "fakerphp/faker", "version": "1.0"}]}',
    ];

    public string $root;

    public string $repo;

    public string $evidence;

    public string $project;

    public function __construct()
    {
        $root = \sys_get_temp_dir() . '/verify-stub-' . \bin2hex(\random_bytes(6));
        \mkdir($root, 0o700, true);
        $this->root = (string) \realpath($root);
        $this->repo = "{$this->root}/repo";
        $this->evidence = "{$this->root}/evidence";
        $this->project = 'alz-verify-repo-' . \mb_substr(\hash('sha256', $this->repo), 0, 8);

        foreach (['bin', 'repo', 'knobs', 'responses', ...\array_map(static fn(string $kind): string => "resources/{$kind}", self::KINDS)] as $dir) {
            \mkdir("{$this->root}/{$dir}", 0o700, true);
        }

        \file_put_contents("{$this->root}/bin/docker", self::STUB);
        \chmod("{$this->root}/bin/docker", 0o755);
        $this->git('init', '-q', '-b', 'develop');
    }

    public function remove(): void
    {
        (new Process(['rm', '-rf', $this->root]))->mustRun();
    }

    public function git(string ...$arguments): string
    {
        $process = new Process(
            ['git', '-c', 'user.name=t', '-c', 'user.email=t@example.invalid', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', ...\array_values($arguments)],
            $this->repo,
            GitHooksInstalledCheck::cleanGitEnv(),
        );

        return \mb_trim($process->mustRun()->getOutput());
    }

    /**
     * Commits these files on top of the checkout, with the bootable defaults in the first commit; null removes one.
     *
     * @param array<string, string|null> $changes
     */
    public function commit(array $changes = []): string
    {
        $files = \is_file("{$this->repo}/seeded") ? $changes : [...self::FILES, 'seeded' => 'yes', ...$changes];

        foreach ($files as $path => $contents) {
            $file = "{$this->repo}/{$path}";

            if ($contents === null) {
                (new Process(['rm', '-f', $file]))->mustRun();

                continue;
            }

            if (!\is_dir(\dirname($file))) {
                \mkdir(\dirname($file), 0o700, true);
            }

            \file_put_contents($file, $contents);
        }

        $this->git('add', '-A');
        $this->git('commit', '-q', '--allow-empty', '-m', 'c');

        return $this->git('rev-parse', 'HEAD');
    }

    public function headUp(string $mode = 'own-postgres'): void
    {
        $this->writeRun($this->evidence, $this->project, $this->git('rev-parse', 'HEAD'), $mode);
    }

    public function baseUp(string $sha): void
    {
        $this->writeRun("{$this->evidence}/baseline", "{$this->project}-base", $sha, 'own-postgres');
    }

    public function knob(string $name, ?string $value): void
    {
        $file = "{$this->root}/knobs/{$name}";

        if ($value === null) {
            \unlink($file);

            return;
        }

        \file_put_contents($file, $value);
    }

    public function respond(string $project, string $raw): void
    {
        \file_put_contents("{$this->root}/responses/{$project}", $raw);
    }

    public function verify(string $verb, string ...$arguments): Process
    {
        return $this->verifyOn($this->evidence, $verb, ...$arguments);
    }

    public function verifyOn(string $evidence, string $verb, string ...$arguments): Process
    {
        $process = $this->command($evidence, $verb, ...$arguments);
        $process->run();

        return $process;
    }

    public function command(string $evidence, string $verb, string ...$arguments): Process
    {
        return new Process(
            [\dirname(__DIR__, 4) . '/.agents/skills/verify/verify', $verb, '--evidence', $evidence, ...\array_values($arguments)],
            $this->repo,
            ['PATH' => "{$this->root}/bin:" . \getenv('PATH'), ...GitHooksInstalledCheck::cleanGitEnv()],
        );
    }

    /**
     * Starts verify, waits for a build the `hang` knob stopped, then kills the entry point outright and the build after it.
     */
    public function killDuringBuild(string $verb): void
    {
        $process = $this->command($this->evidence, $verb);
        $process->start();

        for ($attempt = 0; $attempt < 200 && !\is_file("{$this->root}/hung-build"); ++$attempt) {
            \usleep(50_000);
        }

        if (!\is_file("{$this->root}/hung-build")) {
            $process->stop(0);

            throw new RuntimeException("No build started: {$process->getErrorOutput()}");
        }

        $process->signal(9);
        $process->wait();
        (new Process(['kill', \mb_trim((string) \file_get_contents("{$this->root}/hung-build"))]))->run();
    }

    /**
     * Writes the fixture checkout's `.env`, the main env file of a run on it, left uncommitted.
     */
    public function env(string $contents): void
    {
        \file_put_contents("{$this->repo}/.env", $contents);
    }

    /**
     * @return list<string> every resource of that project, as <kind>/<project>__<name>
     */
    public function resources(string $project): array
    {
        $found = [];

        foreach (self::KINDS as $kind) {
            foreach (\glob("{$this->root}/resources/{$kind}/{$project}__*") ?: [] as $file) {
                $found[] = "{$kind}/" . \basename($file);
            }
        }

        return $found;
    }

    public function calls(): string
    {
        return \is_file("{$this->root}/docker-calls") ? (string) \file_get_contents("{$this->root}/docker-calls") : '';
    }

    public function clearCalls(): void
    {
        \file_put_contents("{$this->root}/docker-calls", '');
    }

    public function addResource(string $kind, string $project, string $name): void
    {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new RuntimeException("Unknown resource kind {$kind}");
        }

        \file_put_contents("{$this->root}/resources/{$kind}/{$project}__{$name}", '');
    }

    private function writeRun(string $evidence, string $project, string $sha, string $mode): void
    {
        \mkdir("{$evidence}/.verify", 0o700, true);
        \mkdir("{$evidence}/logs", 0o700, true);
        \file_put_contents("{$evidence}/.verify/state.env", "mode={$mode}\nproject={$project}\ntop={$this->repo}\nsha={$sha}\nenv_file={$this->root}/main.env\nenv_missing=yes\nbase_url=http://127.0.0.1:5000\n");

        foreach (['compose.env', 'compose.yaml', 'compose.caller-supabase.yaml', 'run.env'] as $file) {
            \file_put_contents("{$evidence}/.verify/{$file}", "VERIFY=1\n");
        }

        $this->addResource('container', $project, 'app');
    }
}
