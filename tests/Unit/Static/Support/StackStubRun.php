<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

use Symfony\Component\Process\Process;

/**
 * The real bin/stack against a fixture git worktree, with `docker` and `curl` stubs first on PATH that record each call
 * and keep the stack's labelled resources as files, one directory per kind.
 */
final readonly class StackStubRun
{
    public const array KINDS = ['container', 'network', 'volume', 'image'];

    public const string CHOKIDAR = '5.0.0';

    private const array TOOLS = [
        'awk', 'basename', 'bash', 'cat', 'chmod', 'curl', 'cut', 'dirname', 'docker', 'env', 'git', 'head', 'jq', 'mkdir',
        'openssl', 'perl', 'rm', 'sed', 'sh', 'shasum', 'sort', 'tail', 'tr', 'wc',
    ];

    private const string DOCKER = <<<'SH'
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
        remove() { kind="$1"; shift; [ "$(knob sticky)" = "$kind" ] && exit 0; for a in "$@"; do case "$a" in -*) ;; *) rm -f "$S/resources/$kind/$a" ;; esac; done; exit 0; }
        case "$1 $2" in
            "info "*) [ "$(knob fail)" = info ] && exit 1; exit 0 ;;
            "ps "*) list container "$@" ;;
            "rm "*) shift; remove container "$@" ;;
            "network ls" | "volume ls" | "image ls") list "$@" ;;
            "network rm" | "volume rm" | "image rm") k="$1"; shift 2; remove "$k" "$@" ;;
            "inspect "*)
                svc="${4##*__}"
                knob "state-$svc" && exit 0
                [ "$svc" = seed ] && [ "$3" = '{{.State.Status}} {{.State.ExitCode}}' ] && echo 'exited 0' && exit 0
                [ "$3" = '{{.State.Status}}' ] && echo running || echo 'running 0 healthy'
                exit 0
                ;;
            "build "*)
                project=''; kind=runtime
                for a in "$@"; do
                    case "$a" in
                        com.docker.compose.project=*) project="${a#com.docker.compose.project=}" ;;
                        */Dockerfile.local) kind=local ;;
                    esac
                done
                : >"$S/resources/image/${project}__$kind"
                [ "$(knob fail)" = "build-$kind" ] && echo "stub $kind build error" && exit 1
                exit 0
                ;;
        esac
        [ "$1" = compose ] || exit 1
        project="$3"; shift 3; shared=no
        while :; do
            case "$1" in
                -f) case "$2" in *shared-supabase*) shared=yes ;; esac; shift 2 ;;
                --project-directory | --profile | --env-file) shift 2 ;;
                *) break ;;
            esac
        done
        verb="$1"; shift
        case "$verb" in
            up)
                for s in app worker redis; do : >"$S/resources/container/${project}__$s"; done
                if [ "$shared" = yes ]; then : >"$S/resources/container/${project}__relay"
                else for s in seed pgsql auth-migrate; do : >"$S/resources/container/${project}__$s"; done; fi
                : >"$S/resources/network/${project}__default"
                : >"$S/resources/volume/${project}__data"
                [ "$(knob fail)" = up ] && exit 1
                ;;
            down) for k in container network volume; do [ "$(knob sticky)" = "$k" ] || rm -f "$S/resources/$k/${project}__"*; done ;;
            port) [ "$(knob fail)" = port ] || echo 127.0.0.1:5000 ;;
            logs) echo "stub log of $*" ;;
            exec)
                case " $* " in
                    *' sh '*)
                        while [ "$1" != sh ]; do shift; done
                        SUPABASE_LOCAL_BYPASS_SECRET=stub-secret exec "$@"
                        ;;
                esac
                [ "$(knob fail)" = exec ] && echo 'stub exec error' && exit 1
                echo "stub exec $*"
                ;;
        esac
        exit 0
        SH;

    private const string CURL = <<<'SH'
        #!/bin/sh
        S="$(cd "$(dirname "$0")/.." && pwd -P)"
        for a in "$@"; do printf '%s\n' "$a"; done >"$S/curl-argv"
        cat >"$S/curl-stdin"
        printf 'stub body\nstatus=200\n'
        SH;

    public string $root;

    public string $repo;

    public string $project;

    public function __construct()
    {
        $root = \sys_get_temp_dir() . '/stack-stub-' . \bin2hex(\random_bytes(6));
        \mkdir($root, 0o700, true);
        $this->root = (string) \realpath($root);
        $this->repo = "{$this->root}/repo";
        $this->project = 'alz-dev-repo-' . \mb_substr(\hash('sha256', $this->repo), 0, 8);

        foreach (['bin', 'repo', 'knobs', ...\array_map(static fn(string $kind): string => "resources/{$kind}", self::KINDS)] as $dir) {
            \mkdir("{$this->root}/{$dir}", 0o700, true);
        }

        foreach (['docker' => self::DOCKER, 'curl' => self::CURL] as $name => $script) {
            \file_put_contents("{$this->root}/bin/{$name}", $script);
            \chmod("{$this->root}/bin/{$name}", 0o755);
        }

        (new Process(['git', 'init', '-q'], $this->repo, GitHooksInstalledCheck::cleanGitEnv()))->mustRun();
        $this->write('vendor/autoload.php', "<?php\n");
        $this->write('database/baseline/versions.env', "SUPABASE_POSTGRES_VERSION=1.0.0\nSUPABASE_AUTH_VERSION=v1.0.0\n");
        $this->write('package-lock.json', '{"packages": {"node_modules/chokidar": {"version": "' . self::CHOKIDAR . '"}}}');
    }

    /**
     * A PATH of symlinks to the tools bin/stack and the stubs use, minus one, so a missing tool can be simulated.
     */
    public function pathWithout(string $tool): string
    {
        $dir = "{$this->root}/path-without-{$tool}";
        \mkdir($dir, 0o700, true);
        $system = \explode(':', (string) \getenv('PATH'));

        foreach (self::TOOLS as $name) {
            if ($name === $tool) {
                continue;
            }

            foreach (["{$this->root}/bin", ...$system] as $candidate) {
                if (\is_file("{$candidate}/{$name}") && \is_executable("{$candidate}/{$name}")) {
                    \symlink("{$candidate}/{$name}", "{$dir}/{$name}");

                    break;
                }
            }
        }

        return $dir;
    }

    public function remove(): void
    {
        (new Process(['rm', '-rf', $this->root]))->mustRun();
    }

    public function write(string $path, ?string $contents): void
    {
        $file = "{$this->repo}/{$path}";

        if ($contents === null) {
            (new Process(['rm', '-f', $file]))->mustRun();

            return;
        }

        if (!\is_dir(\dirname($file))) {
            \mkdir(\dirname($file), 0o700, true);
        }

        \file_put_contents($file, $contents);
    }

    public function read(string $path): string
    {
        return (string) \file_get_contents("{$this->repo}/{$path}");
    }

    public function knob(string $name, string $value): void
    {
        \file_put_contents("{$this->root}/knobs/{$name}", $value);
    }

    /**
     * @param array<string, string> $env
     */
    public function stack(array $env, string ...$arguments): Process
    {
        $process = new Process(
            [\dirname(__DIR__, 4) . '/bin/stack', ...\array_values($arguments)],
            $this->repo,
            ['PATH' => "{$this->root}/bin:" . \getenv('PATH'), 'ALZ_STACK_PORT' => false, ...GitHooksInstalledCheck::cleanGitEnv(), ...$env],
        );
        $process->run();

        return $process;
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

    public function addResource(string $kind, string $project, string $name): void
    {
        \file_put_contents("{$this->root}/resources/{$kind}/{$project}__{$name}", '');
    }

    public function calls(): string
    {
        return \is_file("{$this->root}/docker-calls") ? (string) \file_get_contents("{$this->root}/docker-calls") : '';
    }

    public function clearCalls(): void
    {
        \file_put_contents("{$this->root}/docker-calls", '');
    }

    /**
     * @return list<string>
     */
    public function curlArgv(): array
    {
        return \explode("\n", \mb_rtrim((string) \file_get_contents("{$this->root}/curl-argv"), "\n"));
    }

    public function curlStdin(): string
    {
        return (string) \file_get_contents("{$this->root}/curl-stdin");
    }
}
