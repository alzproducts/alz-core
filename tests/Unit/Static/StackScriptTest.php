<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;
use Tests\Unit\Static\Support\StackStubRun;

/**
 * bin/stack's subcommands and refusals, against stubbed docker and curl; the runtime journey is the slice Check's.
 */
#[CoversNothing]
#[Group('repo-static')]
final class StackScriptTest extends TestCase
{
    private StackStubRun $run;

    /** @var array<int, resource> */
    private array $listeners = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->run = new StackStubRun();
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->listeners as $listener) {
            \fclose($listener);
        }

        $this->run->remove();

        parent::tearDown();
    }

    #[Test]
    public function up_builds_both_images_starts_app_and_worker_and_prints_the_port(): void
    {
        $up = $this->run->stack([], 'up');

        $this->assertSame(0, $up->getExitCode(), $up->getErrorOutput());
        $this->assertSame("project={$this->run->project}\nurl=http://127.0.0.1:5000\nport=5000\n", $up->getOutput());
        $this->assertStringStartsWith('alz-dev-repo-', $this->run->project);

        $calls = $this->run->calls();
        $this->assertStringContainsString("build --progress plain --target runtime --label com.docker.compose.project={$this->run->project} -t alz-dev-runtime:{$this->run->project}", $calls);
        $this->assertStringContainsString("--build-arg RUNTIME_IMAGE=alz-dev-runtime:{$this->run->project} --build-arg CHOKIDAR_VERSION=" . StackStubRun::CHOKIDAR, $calls);
        $this->assertMatchesRegularExpression('/^compose -p ' . \preg_quote($this->run->project, '/') . ' .*--profile own-postgres .*up -d --wait --wait-timeout 900 app worker$/m', $calls);

        $env = $this->run->read('tmp/stack/compose.env');
        $this->assertStringContainsString("STACK_IMAGE=alz-dev-local:{$this->run->project}\n", $env);
        $this->assertStringContainsString("STACK_PORT=\n", $env);
        $this->assertStringContainsString("SUPABASE_POSTGRES_VERSION=1.0.0\nSUPABASE_AUTH_VERSION=v1.0.0\n", $env);
        $this->assertMatchesRegularExpression('/^STACK_DB_PASSWORD=[0-9a-f]{48}$/m', $env);
        $this->assertSame('0777', \mb_substr(\sprintf('%o', \fileperms("{$this->run->repo}/storage/logs")), -4));
    }

    #[Test]
    public function up_fills_only_the_keys_the_env_leaves_blank(): void
    {
        $this->run->write('.env', "APP_KEY=base64:kept\nSUPABASE_LOCAL_BYPASS_SECRET=\nEMAIL_PRIMARY=''\n");

        $this->assertSame(0, $this->run->stack([], 'up')->getExitCode());

        $fill = $this->run->read('tmp/stack/fill.env');
        $this->assertStringNotContainsString('APP_KEY', $fill);
        $this->assertMatchesRegularExpression("/^API_KEY_ENCRYPTION_SECRET='[0-9a-f]{64}'$/m", $fill);
        $this->assertMatchesRegularExpression("/^SUPABASE_LOCAL_BYPASS_SECRET='[0-9a-f]{48}'$/m", $fill);
        $this->assertStringContainsString("EMAIL_PRIMARY='stack@example.invalid'\n", $fill);
        $this->assertStringContainsString("SUPABASE_LOCAL_TEST_EMAIL='dev@alzadmin.test'\n", $fill);
    }

    #[Test]
    public function up_on_a_running_stack_recreates_only_app_and_worker_and_keeps_its_env(): void
    {
        $this->assertSame(0, $this->run->stack([], 'up')->getExitCode());
        $env = $this->run->read('tmp/stack/compose.env');
        $this->run->clearCalls();

        $again = $this->run->stack([], 'up');

        $this->assertSame(0, $again->getExitCode(), $again->getErrorOutput());
        $this->assertSame($env, $this->run->read('tmp/stack/compose.env'));
        $this->assertStringContainsString('up -d --wait --wait-timeout 900 --force-recreate --no-deps app worker', $this->run->calls());
        $this->assertStringNotContainsString('build ', $this->run->calls());
    }

    #[Test]
    public function up_with_a_free_fixed_port_publishes_it(): void
    {
        $port = $this->freePort();

        $this->assertSame(0, $this->run->stack(['ALZ_STACK_PORT' => (string) $port], 'up')->getExitCode());

        $this->assertStringContainsString("STACK_PORT={$port}\n", $this->run->read('tmp/stack/compose.env'));
    }

    #[Test]
    public function up_refuses_a_fixed_port_another_process_holds_and_names_it(): void
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $port = (int) \mb_substr((string) \stream_socket_get_name($server, false), \mb_strlen('127.0.0.1:'));

        $up = $this->run->stack(['ALZ_STACK_PORT' => (string) $port], 'up');
        \fclose($server);

        $this->assertRefusedBeforeBuild($up, 75, "port {$port} (ALZ_STACK_PORT) is held by another process");
    }

    #[Test]
    public function up_refuses_a_fixed_port_that_is_not_a_port_number(): void
    {
        $this->assertRefusedBeforeBuild($this->run->stack(['ALZ_STACK_PORT' => '70000'], 'up'), 64, 'ALZ_STACK_PORT is not a port number: 70000');
    }

    #[Test]
    public function up_refuses_an_unreachable_docker_daemon(): void
    {
        $this->run->knob('fail', 'info');

        $this->assertRefusedBeforeBuild($this->run->stack([], 'up'), 75, 'docker daemon not reachable');
    }

    #[Test]
    public function up_refuses_a_worktree_without_vendor(): void
    {
        $this->run->write('vendor/autoload.php', null);

        $this->assertRefusedBeforeBuild($this->run->stack([], 'up'), 75, 'run make install first');
    }

    #[Test]
    public function up_refuses_env_keys_compose_would_interpolate_and_never_prints_their_values(): void
    {
        $this->run->write('.env', "FAKE_SECRET=ab\$cd\nFINE=1\nQUOTED='x\$y'\n");

        $up = $this->run->stack([], 'up');

        $this->assertRefusedBeforeBuild($up, 75, 'single-quote these keys in .env');
        $this->assertStringContainsString('FAKE_SECRET', $up->getErrorOutput());
        $this->assertStringNotContainsString('ab$cd', $up->getErrorOutput());
        $this->assertStringNotContainsString('QUOTED', $up->getErrorOutput());
    }

    #[Test]
    public function up_refuses_containers_without_the_generated_env(): void
    {
        $this->run->addResource('container', $this->run->project, 'app');

        $this->assertRefusedBeforeBuild($this->run->stack([], 'up'), 75, 'run bin/stack down first');
    }

    #[Test]
    public function up_refuses_an_unknown_argument(): void
    {
        $this->assertRefusedBeforeBuild($this->run->stack([], 'up', '--nope'), 64, 'up: unknown argument: --nope');
    }

    #[Test]
    public function up_reports_a_failed_image_build_with_its_log(): void
    {
        $this->run->knob('fail', 'build-local');

        $up = $this->run->stack([], 'up');

        $this->assertSame(1, $up->getExitCode());
        $this->assertStringContainsString('stub local build error', $up->getErrorOutput());
        $this->assertStringContainsString('the local image build failed: tmp/stack/build-local.log', $up->getErrorOutput());
    }

    #[Test]
    public function up_names_the_first_service_that_did_not_start(): void
    {
        $this->run->knob('fail', 'up');
        $this->run->knob('state-seed', 'exited 1 ');

        $up = $this->run->stack([], 'up');

        $this->assertSame(1, $up->getExitCode());
        $this->assertStringContainsString('seed did not start', $up->getErrorOutput());
        $this->assertStringContainsString('stub log of --no-color --tail 40 seed', $up->getErrorOutput());
    }

    #[Test]
    public function down_removes_every_resource_of_its_project_and_no_other(): void
    {
        $this->assertSame(0, $this->run->stack([], 'up')->getExitCode());
        $this->run->addResource('container', $this->run->project, 'orphan');
        $this->run->addResource('container', 'alz-dev-other-12345678', 'app');

        $down = $this->run->stack([], 'down');

        $this->assertSame(0, $down->getExitCode(), $down->getErrorOutput());
        $this->assertSame("down ok project={$this->run->project}\n", $down->getOutput());
        $this->assertSame([], $this->run->resources($this->run->project));
        $this->assertSame(['container/alz-dev-other-12345678__app'], $this->run->resources('alz-dev-other-12345678'));
        $this->assertDirectoryDoesNotExist("{$this->run->repo}/tmp/stack");
        $this->assertStringContainsString('down -v --remove-orphans', $this->run->calls());
    }

    #[Test]
    public function down_refuses_arguments(): void
    {
        $this->assertRefusal($this->run->stack([], 'down', 'app'), 64, 'down takes no arguments');
    }

    #[Test]
    public function port_prints_the_published_host_port(): void
    {
        $this->assertSame(0, $this->run->stack([], 'up')->getExitCode());

        $port = $this->run->stack([], 'port');

        $this->assertSame(0, $port->getExitCode());
        $this->assertSame("5000\n", $port->getOutput());
    }

    #[Test]
    public function port_refuses_a_stack_that_is_not_up(): void
    {
        $this->assertRefusal($this->run->stack([], 'port'), 1, 'the stack is not up');
    }

    #[Test]
    public function logs_follows_the_app_by_default_or_the_named_service(): void
    {
        $this->assertSame(0, $this->run->stack([], 'up')->getExitCode());

        $this->assertSame("stub log of -f app\n", $this->run->stack([], 'logs')->getOutput());
        $this->assertSame("stub log of -f worker\n", $this->run->stack([], 'logs', 'worker')->getOutput());
    }

    #[Test]
    public function logs_refuses_an_unknown_service(): void
    {
        $this->assertRefusal($this->run->stack([], 'logs', 'nginx'), 64, 'unknown service: nginx');
    }

    #[Test]
    public function logs_refuses_a_stack_that_is_not_up(): void
    {
        $this->assertRefusal($this->run->stack([], 'logs'), 1, 'the stack is not up');
    }

    #[Test]
    public function curl_sends_the_bypass_request_inside_the_app_container(): void
    {
        $this->assertSame(0, $this->run->stack([], 'up')->getExitCode());

        $curl = $this->run->stack([], 'curl', 'GET', '/api/products?page[size]=1');

        $this->assertSame(0, $curl->getExitCode(), $curl->getErrorOutput());
        $this->assertSame("stub body\nstatus=200\n", $curl->getOutput());
        $this->assertStringContainsString('compose -p ' . $this->run->project, $this->run->calls());
        $this->assertStringContainsString(' exec -T app sh -c ', $this->run->calls());
        $this->assertSame(
            ['-sS', '--globoff', '-X', 'GET', '-H', 'Accept: application/json', '-H', 'X-Local-Bypass: stub-secret', '-w', '\nstatus=%{http_code}\n', 'http://127.0.0.1:8000/api/products?page[size]=1'],
            $this->run->curlArgv(),
        );
    }

    #[Test]
    public function curl_sends_a_piped_body_as_json(): void
    {
        $this->assertSame(0, $this->run->stack([], 'up')->getExitCode());

        $process = new Process(
            [\dirname(__DIR__, 3) . '/bin/stack', 'curl', 'POST', '/api/thing'],
            $this->run->repo,
            ['PATH' => "{$this->run->root}/bin:" . \getenv('PATH'), 'ALZ_STACK_PORT' => false],
            '{"a":1}',
        );
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('{"a":1}', $this->run->curlStdin());
        $this->assertSame(['-H', 'Content-Type: application/json', '--data-binary', '@-', '-sS'], \array_slice($this->run->curlArgv(), 0, 5));
    }

    #[Test]
    public function curl_refuses_a_lowercase_method(): void
    {
        $this->assertRefusal($this->run->stack([], 'curl', 'get', '/up'), 64, 'bad method: get');
    }

    #[Test]
    public function curl_refuses_a_path_without_a_leading_slash(): void
    {
        $this->assertRefusal($this->run->stack([], 'curl', 'GET', 'http://example.invalid/'), 64, 'path must start with /');
    }

    #[Test]
    public function curl_refuses_a_stack_that_is_not_up(): void
    {
        $this->assertRefusal($this->run->stack([], 'curl', 'GET', '/up'), 1, 'the stack is not up');
    }

    #[Test]
    public function it_refuses_an_unknown_command_or_none(): void
    {
        $this->assertRefusal($this->run->stack([], 'serve'), 64, 'unknown command: serve');
        $this->assertRefusal($this->run->stack([]), 64, 'Usage: bin/stack <command>');
    }

    #[Test]
    public function help_prints_the_usage(): void
    {
        $this->assertRefusal($this->run->stack([], '--help'), 64, 'curl <METHOD> <path>');
    }

    #[Test]
    public function it_refuses_a_cwd_outside_a_git_work_tree(): void
    {
        $process = new Process(
            [\dirname(__DIR__, 3) . '/bin/stack', 'up'],
            $this->run->root,
            [
                'PATH' => "{$this->run->root}/bin:" . \getenv('PATH'),
                'GIT_CEILING_DIRECTORIES' => \dirname($this->run->root),
                // intentional: git hooks export these, which would locate the real repo from any cwd.
                'GIT_DIR' => false,
                'GIT_WORK_TREE' => false,
                'GIT_INDEX_FILE' => false,
                'GIT_COMMON_DIR' => false,
                'GIT_PREFIX' => false,
            ],
        );
        $process->run();

        $this->assertRefusal($process, 64, 'run from inside the worktree');
    }

    #[Test]
    public function up_refuses_a_host_without_docker(): void
    {
        $this->assertRefusedBeforeBuild($this->run->stack(['PATH' => $this->run->pathWithout('docker')], 'up'), 75, 'docker not found');
    }

    #[Test]
    public function up_refuses_a_host_without_jq(): void
    {
        $this->assertRefusedBeforeBuild($this->run->stack(['PATH' => $this->run->pathWithout('jq')], 'up'), 75, 'up needs jq');
    }

    #[Test]
    public function up_refuses_a_lock_file_without_chokidar(): void
    {
        $this->run->write('package-lock.json', '{"packages": {}}');

        $this->assertRefusedBeforeBuild($this->run->stack([], 'up'), 1, 'package-lock.json locks no chokidar version');
    }

    #[Test]
    public function up_refuses_a_pin_file_without_the_supabase_versions(): void
    {
        $this->run->write('database/baseline/versions.env', "OTHER=1\n");

        $this->assertRefusedBeforeBuild($this->run->stack([], 'up'), 1, 'versions.env lacks the Supabase pins');
    }

    #[Test]
    public function up_refuses_an_unreadable_env(): void
    {
        $this->run->write('.env', "A=1\n");
        \chmod("{$this->run->repo}/.env", 0o000);

        $this->assertRefusedBeforeBuild($this->run->stack([], 'up'), 75, 'cannot read');
    }

    #[Test]
    public function up_refuses_a_stack_that_publishes_no_port(): void
    {
        $this->run->knob('fail', 'port');

        $this->assertRefusal($this->run->stack([], 'up'), 1, 'the app publishes no host port');
    }

    #[Test]
    public function down_fails_when_a_resource_survives(): void
    {
        $this->assertSame(0, $this->run->stack([], 'up')->getExitCode());
        $this->run->knob('sticky', 'volume');

        $down = $this->run->stack([], 'down');

        $this->assertRefusal($down, 1, "down left volume ls residue: {$this->run->project}__data");
    }

    #[Test]
    public function port_refuses_arguments(): void
    {
        $this->assertRefusal($this->run->stack([], 'port', 'app'), 64, 'port takes no arguments');
    }

    #[Test]
    public function port_refuses_a_stack_that_publishes_no_port(): void
    {
        $this->assertSame(0, $this->run->stack([], 'up')->getExitCode());
        $this->run->knob('fail', 'port');

        $this->assertRefusal($this->run->stack([], 'port'), 1, 'the app publishes no host port');
    }

    #[Test]
    public function logs_refuses_more_than_one_service(): void
    {
        $this->assertRefusal($this->run->stack([], 'logs', 'app', 'worker'), 64, 'logs takes at most one service');
    }

    #[Test]
    public function curl_refuses_the_wrong_argument_count(): void
    {
        $this->assertRefusal($this->run->stack([], 'curl', 'GET'), 64, 'curl wants <METHOD> <path>');
    }

    #[Test]
    public function the_local_image_builds_from_the_runtime_target(): void
    {
        $root = \dirname(__DIR__, 3);
        $dockerfile = (string) \file_get_contents("{$root}/Dockerfile.local");

        $this->assertMatchesRegularExpression('/^FROM \$\{RUNTIME_IMAGE\}$/m', $dockerfile);
        $this->assertMatchesRegularExpression('/^FROM [^\n]+ AS runtime$/m', (string) \file_get_contents("{$root}/Dockerfile"));
    }

    #[Test]
    public function up_with_shared_supabase_layers_the_override_without_the_own_postgres_profile(): void
    {
        [$db, $api] = $this->sharedSupabase();

        $up = $this->run->stack([], 'up', '--shared-supabase');

        $this->assertSame(0, $up->getExitCode(), $up->getErrorOutput());
        $this->assertSame("project={$this->run->project}\nurl=http://127.0.0.1:5000\nport=5000\n", $up->getOutput());
        $calls = $this->run->calls();
        $this->assertMatchesRegularExpression('/^compose -p \S+ .*-f \S+\/compose\.local\.yaml -f \S+\/compose\.local\.shared-supabase\.yaml .*up -d --wait --wait-timeout 900 app worker$/m', $calls);
        $this->assertStringNotContainsString('--profile own-postgres', $calls);
        $this->assertStringContainsString('exec -T app php artisan db:ping', $calls);
        $this->assertStringContainsString('port relay 8000', $calls);
        $this->assertContains("container/{$this->run->project}__relay", $this->run->resources($this->run->project));
        $this->assertNotContains("container/{$this->run->project}__seed", $this->run->resources($this->run->project));

        $env = $this->run->read('tmp/stack/compose.env');
        $this->assertStringContainsString("STACK_SHARED_DB_PORT={$db}\nSTACK_SHARED_API_PORT={$api}\n", $env);
        $this->assertStringContainsString("STACK_RELAY_IMAGE=alz-dev-relay:{$this->run->project}\n", $env);
        $this->assertStringContainsString("STACK_PROJECT={$this->run->project}\n", $env);
    }

    #[Test]
    public function up_with_shared_supabase_on_a_running_shared_stack_recreates_only_app_and_worker(): void
    {
        $this->sharedSupabase();
        $this->assertSame(0, $this->run->stack([], 'up', '--shared-supabase')->getExitCode());
        $this->run->clearCalls();

        $again = $this->run->stack([], 'up', '--shared-supabase');

        $this->assertSame(0, $again->getExitCode(), $again->getErrorOutput());
        $this->assertMatchesRegularExpression('/^compose .*shared-supabase\.yaml .*up -d --wait --wait-timeout 900 --force-recreate --no-deps app worker$/m', $this->run->calls());
        $this->assertStringContainsString('exec -T app php artisan db:ping', $this->run->calls());
    }

    #[Test]
    public function up_refuses_a_mode_other_than_the_running_one_and_names_down(): void
    {
        $this->sharedSupabase();
        $this->assertSame(0, $this->run->stack([], 'up')->getExitCode());

        $this->assertRefusal($this->run->stack([], 'up', '--shared-supabase'), 75, 'is up in own-Postgres mode; run bin/stack down before an up in shared-Supabase mode');

        $this->assertSame(0, $this->run->stack([], 'down')->getExitCode());
        $this->assertSame(0, $this->run->stack([], 'up', '--shared-supabase')->getExitCode());

        $this->assertRefusal($this->run->stack([], 'up'), 75, 'is up in shared-Supabase mode; run bin/stack down before an up in own-Postgres mode');
    }

    #[Test]
    public function up_with_shared_supabase_refuses_a_shared_supabase_that_does_not_answer(): void
    {
        $closed = $this->freePort();
        $this->run->write('.env', "DB_PORT={$closed}\nSUPABASE_URL=http://127.0.0.1:{$closed}\n");

        $up = $this->run->stack([], 'up', '--shared-supabase');

        $this->assertRefusedBeforeBuild($up, 75, "the shared Supabase is not answering on 127.0.0.1:{$closed}");
        $this->assertStringContainsString('make supabase-start runs db-reset-full', $up->getErrorOutput());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sharedEnvRefusals(): iterable
    {
        yield 'no DB_PORT' => ["SUPABASE_URL=http://127.0.0.1:54321\n", '--shared-supabase needs DB_PORT in .env'];
        yield 'no SUPABASE_URL' => ["DB_PORT=54322\n", '--shared-supabase needs SUPABASE_URL in .env'];
        yield 'DB_PORT not a port' => ["DB_PORT=pg\nSUPABASE_URL=http://127.0.0.1:54321\n", 'DB_PORT in .env is not a port number: pg'];
        yield 'remote SUPABASE_URL' => ["DB_PORT=54322\nSUPABASE_URL=https://example.supabase.co\n", 'to be http://127.0.0.1:<port>; got https://example.supabase.co'];
    }

    #[Test]
    #[DataProvider('sharedEnvRefusals')]
    public function up_with_shared_supabase_refuses_an_env_without_a_loopback_shared_supabase(string $env, string $message): void
    {
        $this->run->write('.env', $env);

        $this->assertRefusedBeforeBuild($this->run->stack([], 'up', '--shared-supabase'), 64, $message);
    }

    #[Test]
    public function up_with_shared_supabase_fails_when_the_app_cannot_reach_the_shared_database(): void
    {
        $this->sharedSupabase();
        $this->run->knob('fail', 'exec');

        $up = $this->run->stack([], 'up', '--shared-supabase');

        $this->assertRefusal($up, 1, 'the app cannot reach the shared database; check DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env');
    }

    #[Test]
    public function port_logs_curl_and_down_take_the_shared_mode_from_the_relay_container(): void
    {
        $this->sharedSupabase();
        $this->assertSame(0, $this->run->stack([], 'up', '--shared-supabase')->getExitCode());
        $this->run->clearCalls();

        $this->assertSame("5000\n", $this->run->stack([], 'port')->getOutput());
        $this->assertSame("stub log of -f relay\n", $this->run->stack([], 'logs', 'relay')->getOutput());
        $this->assertSame(0, $this->run->stack([], 'curl', 'GET', '/up')->getExitCode());
        $this->assertSame(0, $this->run->stack([], 'down')->getExitCode());

        $composeCalls = \array_values(\array_filter(\explode("\n", $this->run->calls()), static fn(string $call): bool => \str_starts_with($call, 'compose ')));
        $this->assertCount(4, $composeCalls);

        foreach ($composeCalls as $call) {
            $this->assertStringContainsString('compose.local.shared-supabase.yaml', $call);
            $this->assertStringNotContainsString('--profile own-postgres', $call);
        }

        $this->assertStringContainsString('port relay 8000', $composeCalls[0]);
        $this->assertSame([], $this->run->resources($this->run->project));
    }

    #[Test]
    public function the_shared_override_replaces_the_dependencies_and_takes_the_shared_database_from_the_env(): void
    {
        $root = \dirname(__DIR__, 3);
        $base = Yaml::parseFile("{$root}/compose.local.yaml", Yaml::PARSE_CUSTOM_TAGS);
        $shared = Yaml::parseFile("{$root}/compose.local.shared-supabase.yaml", Yaml::PARSE_CUSTOM_TAGS);
        $this->assertIsArray($base);
        $this->assertIsArray($shared);
        $fromEnv = ['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_PORT', 'SUPABASE_URL', 'SHOPWIRED_SALE_CATEGORY_ID'];
        $expected = \array_diff_key($base['x-stack-env'], \array_flip($fromEnv));
        $expected['DB_HOST'] = '127.0.0.1';

        foreach (['app', 'worker'] as $service) {
            $definition = $shared['services'][$service];
            $this->assertSame('service:relay', $definition['network_mode']);
            $this->assertInstanceOf(TaggedValue::class, $definition['depends_on']);
            $this->assertSame('override', $definition['depends_on']->getTag());
            $this->assertSame(['relay', 'redis'], \array_keys($definition['depends_on']->getValue()));
            $this->assertInstanceOf(TaggedValue::class, $definition['environment']);
            $this->assertSame('override', $definition['environment']->getTag());
            $environment = $definition['environment']->getValue();
            \ksort($environment);
            \ksort($expected);
            $this->assertSame($expected, $environment);
        }

        foreach (['seed', 'pgsql', 'auth-migrate'] as $service) {
            $this->assertArrayNotHasKey($service, $shared['services']);
            $this->assertSame(['own-postgres'], $base['services'][$service]['profiles']);
        }
    }

    /**
     * Two loopback listeners standing in for the shared Postgres and Auth API, and a .env pointing at them.
     *
     * @return array{int, int}
     */
    private function sharedSupabase(): array
    {
        $ports = [];

        foreach ([0, 1] as $i) {
            $server = \stream_socket_server('tcp://127.0.0.1:0');
            $this->assertIsResource($server);
            $this->listeners[$i] = $server;
            $ports[$i] = (int) \mb_substr((string) \stream_socket_get_name($server, false), \mb_strlen('127.0.0.1:'));
        }

        $this->run->write('.env', "DB_PORT={$ports[0]}\nSUPABASE_URL=http://127.0.0.1:{$ports[1]}\n");

        return [$ports[0], $ports[1]];
    }

    private function freePort(): int
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $port = (int) \mb_substr((string) \stream_socket_get_name($server, false), \mb_strlen('127.0.0.1:'));
        \fclose($server);

        return $port;
    }

    private function assertRefusal(Process $process, int $code, string $message): void
    {
        $this->assertSame($code, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString($message, $process->getErrorOutput());
        $this->assertSame('', $process->getOutput());
    }

    private function assertRefusedBeforeBuild(Process $process, int $code, string $message): void
    {
        $this->assertRefusal($process, $code, $message);
        $this->assertStringNotContainsString('build ', $this->run->calls());
        $this->assertStringNotContainsString(' up ', $this->run->calls());
    }
}
