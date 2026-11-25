<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Static\Support\GitHooksInstalledCheck;
use Tests\Unit\Static\Support\VerifyStubRun;

/**
 * `verify up --supabase-url … --supabase-db-url …` refuses a bad caller before any Docker call and prints no credential.
 */
#[CoversNothing]
#[Group('repo-static')]
final class VerifyCallerSupabaseFlagsTest extends TestCase
{
    private const string URL = 'http://127.0.0.1:61001';

    private const string DB_URL = 'postgresql://postgres:hunter2secret@127.0.0.1:61002/postgres';

    private string $sandbox = '';

    private ?Process $server = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = \sys_get_temp_dir() . '/verify-flags-' . \bin2hex(\random_bytes(6));
        \mkdir("{$this->sandbox}/bin", 0o700, true);
        \file_put_contents("{$this->sandbox}/bin/docker", "#!/bin/sh\necho \"\$@\" >>\"{$this->sandbox}/docker-calls\"\nexit 1\n");
        \chmod("{$this->sandbox}/bin/docker", 0o755);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->server?->stop(0);
        (new Process(['rm', '-rf', $this->sandbox]))->mustRun();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function refusals(): iterable
    {
        $url = static fn(string $value): array => ['--supabase-url', $value, '--supabase-db-url', self::DB_URL];
        $db = static fn(string $value): array => ['--supabase-url', self::URL, '--supabase-db-url', $value];
        $dbAt = static fn(string $hostport): array => $db("postgresql://postgres:hunter2secret@{$hostport}/postgres");

        yield 'only the URL' => [['--supabase-url', self::URL], '--supabase-url refused (127.0.0.1:61001)'];
        yield 'only the database URL' => [['--supabase-db-url', self::DB_URL], '--supabase-db-url refused (127.0.0.1:61002)'];
        yield 'the URL without a value' => [['--supabase-url'], '--supabase-url needs a value'];
        yield 'the URL twice' => [['--supabase-url', self::URL, '--supabase-url', self::URL], '--supabase-url refused (127.0.0.1:61001): given twice'];
        yield 'the database URL twice' => [['--supabase-db-url', self::DB_URL, '--supabase-db-url', self::DB_URL], '--supabase-db-url refused (127.0.0.1:61002): given twice'];
        yield 'the same port twice' => [$dbAt('localhost:61001'), '--supabase-db-url refused (localhost:61001): both flags'];

        foreach (['127.0.0.1', 'localhost'] as $host) {
            foreach (['8000', '54321', '54322', '65536'] as $port) {
                yield "URL {$host}:{$port}" => [$url("http://{$host}:{$port}"), "--supabase-url refused ({$host}:{$port})"];
                yield "database {$host}:{$port}" => [$dbAt("{$host}:{$port}"), "--supabase-db-url refused ({$host}:{$port})"];
            }
        }

        foreach (['[::1]:54321', '[::1]:54322', '[::1]:61005', '10.0.0.5:61005', 'db.example.test:61005', '127.0.0.2:54321', '127.1:54321', 'LOCALHOST:54321', '127.0.0.1:054321', '127.0.0.1:0'] as $hostport) {
            yield "URL {$hostport}" => [$url("http://{$hostport}"), "--supabase-url refused ({$hostport})"];
            yield "database {$hostport}" => [$dbAt($hostport), "--supabase-db-url refused ({$hostport})"];
        }

        yield 'URL without a port' => [$url('http://localhost'), '--supabase-url refused (localhost)'];
        yield 'URL without a port, another host' => [$url('http://hunter2secret'), '--supabase-url refused: '];
        yield 'database without a port' => [$dbAt('127.0.0.1'), '--supabase-db-url refused (127.0.0.1)'];
        yield 'https' => [$url('https://127.0.0.1:61001'), '--supabase-url refused (127.0.0.1:61001)'];
        yield 'a trailing slash' => [$url('http://127.0.0.1:61001/'), '--supabase-url refused (127.0.0.1:61001)'];
        yield 'a path' => [$url('http://127.0.0.1:61001/auth/v1'), '--supabase-url refused (127.0.0.1:61001)'];
        yield 'an origin without a scheme' => [$url('127.0.0.1:61001'), '--supabase-url refused: '];
        yield 'an empty URL' => [$url(''), '--supabase-url refused: '];
        yield 'credentials in the URL' => [$url('http://postgres:hunter2secret@127.0.0.1:61001'), '--supabase-url refused (127.0.0.1:61001)'];
        yield 'a query string' => [$db(self::DB_URL . '?host=db.example.test'), '--supabase-db-url refused (127.0.0.1:61002)'];
        yield 'another user' => [$db('postgresql://hunter2secret:x@127.0.0.1:61002/postgres'), '--supabase-db-url refused (127.0.0.1:61002)'];
        yield 'another database' => [$db('postgresql://postgres:x@127.0.0.1:61002/hunter2secret'), '--supabase-db-url refused (127.0.0.1:61002)'];
        yield 'another scheme' => [$db('postgres://postgres:hunter2secret@127.0.0.1:61002/postgres'), '--supabase-db-url refused (127.0.0.1:61002)'];
        yield 'no password' => [$db('postgresql://postgres@127.0.0.1:61002/postgres'), '--supabase-db-url refused (127.0.0.1:61002)'];
        yield 'a missing @' => [$db('postgresql://postgres:hunter2secret127.0.0.1:61002/postgres'), '--supabase-db-url refused: '];
        yield 'an @ in the password' => [$db('postgresql://postgres:hunter2@secret@127.0.0.1:61002/postgres'), '--supabase-db-url refused: '];
        yield 'a slash in the password' => [$db('postgresql://postgres:hunter2/secret@127.0.0.1:61002/postgres'), '--supabase-db-url refused (127.0.0.1:61002)'];
        yield 'the equals form' => [['--supabase-db-url=' . self::DB_URL], 'unknown argument: --supabase-db-url'];
        yield 'a bare positional' => [[self::DB_URL], 'unknown argument'];
    }

    /**
     * @param list<string> $args
     */
    #[Test]
    #[DataProvider('refusals')]
    public function a_bad_flag_is_refused_before_docker_without_printing_a_credential(array $args, string $message): void
    {
        $up = $this->up($args);
        $output = $up->getOutput() . $up->getErrorOutput();

        $this->assertStringContainsString("verify: up: {$message}", $output);
        $this->assertSame(64, $up->getExitCode());
        $this->assertStringNotContainsString('hunter2', $output);
        $this->assertStringNotContainsString('secret', $output);
        $this->assertStringNotContainsString('://', $output);
        $this->assertFileDoesNotExist("{$this->sandbox}/docker-calls");
    }

    #[Test]
    public function an_address_where_nothing_listens_exits_75_naming_it(): void
    {
        [$db, $dbPort] = self::listen('127.0.0.1');
        $closed = self::freePort();

        $this->assertNotReady($this->up(self::flags("http://127.0.0.1:{$closed}", $dbPort)), "--supabase-url refused (127.0.0.1:{$closed})");
        $this->assertNotReady($this->up(self::flags("http://127.0.0.1:{$dbPort}", $closed)), "--supabase-db-url refused (127.0.0.1:{$closed})");
        \fclose($db);
    }

    #[Test]
    public function localhost_does_not_reach_a_listener_bound_only_to_ipv6(): void
    {
        [$db, $dbPort] = self::listen('127.0.0.1');
        [$ipv6, $port] = self::listen('[::1]');

        $this->assertNotReady($this->up(self::flags("http://localhost:{$port}", $dbPort)), "--supabase-url refused (localhost:{$port})");
        \fclose($ipv6);
        \fclose($db);
    }

    #[Test]
    public function an_address_without_a_readable_issuer_exits_75(): void
    {
        [$db, $dbPort] = self::listen('127.0.0.1');
        $port = $this->serveIssuer('');

        $this->assertNotReady($this->up(self::flags("http://127.0.0.1:{$port}", $dbPort)), "--supabase-url refused (127.0.0.1:{$port}): no readable issuer");
        \fclose($db);
    }

    #[Test]
    public function localhost_reaches_an_ipv4_listener_and_a_different_issuer_is_refused_naming_both(): void
    {
        [$db, $dbPort] = self::listen('127.0.0.1');
        $port = $this->serveIssuer('http://127.0.0.1:%d/auth/v1');
        $up = $this->up(self::flags("http://localhost:{$port}", $dbPort));

        $this->assertStringContainsString("want http://localhost:{$port}/auth/v1, Supabase reports http://127.0.0.1:{$port}/auth/v1", $up->getErrorOutput());
        $this->assertSame(64, $up->getExitCode());
        $this->assertFileDoesNotExist("{$this->sandbox}/docker-calls");
        \fclose($db);
    }

    #[Test]
    public function an_accepted_caller_goes_on_to_docker(): void
    {
        [$db, $dbPort] = self::listen('127.0.0.1');
        $port = $this->serveIssuer('http://127.0.0.1:%d/auth/v1');
        $up = $this->up(self::flags("http://127.0.0.1:{$port}", $dbPort));

        $this->assertStringContainsString('docker daemon not reachable', $up->getErrorOutput());
        $this->assertFileExists("{$this->sandbox}/docker-calls");
        \fclose($db);
    }

    #[Test]
    public function a_bare_dollar_in_the_main_env_file_does_not_stop_a_run_that_never_loads_it(): void
    {
        $run = new VerifyStubRun();
        [$db, $dbPort] = self::listen('127.0.0.1');

        try {
            $run->commit();
            $run->env("FAKE_SECRET=ab\$cd\$\n");
            $run->knob('fail', 'build');
            $port = $this->serveIssuer('http://127.0.0.1:%d/auth/v1');

            $run->verify('up', ...self::flags("http://127.0.0.1:{$port}", $dbPort));

            $this->assertStringContainsString(' build app', $run->calls());
        } finally {
            \fclose($db);
            $run->remove();
        }
    }

    private function assertNotReady(Process $up, string $message): void
    {
        $this->assertStringContainsString("verify: up: {$message}", $up->getErrorOutput());
        $this->assertSame(75, $up->getExitCode());
        $this->assertStringNotContainsString('hunter2secret', $up->getOutput() . $up->getErrorOutput());
        $this->assertFileDoesNotExist("{$this->sandbox}/docker-calls");
    }

    /**
     * @return list<string>
     */
    private static function flags(string $url, int $dbPort): array
    {
        return ['--supabase-url', $url, '--supabase-db-url', "postgresql://postgres:hunter2secret@127.0.0.1:{$dbPort}/postgres"];
    }

    /**
     * @param list<string> $args
     */
    private function up(array $args): Process
    {
        $root = \dirname(__DIR__, 3);
        $process = new Process(
            ["{$root}/.agents/skills/verify/verify", 'up', '--evidence', "{$this->sandbox}/evidence", ...$args],
            $root,
            ['PATH' => "{$this->sandbox}/bin:" . \getenv('PATH'), ...GitHooksInstalledCheck::cleanGitEnv()],
        );
        $process->run();

        return $process;
    }

    /**
     * @return array{resource, int}
     */
    private static function listen(string $host): array
    {
        $socket = \stream_socket_server("tcp://{$host}:0");

        if ($socket === false) {
            throw new RuntimeException('Cannot listen on the loopback address');
        }

        $name = (string) \stream_socket_get_name($socket, false);

        return [$socket, (int) \mb_substr($name, (int) \mb_strrpos($name, ':') + 1)];
    }

    private static function freePort(): int
    {
        [$socket, $port] = self::listen('127.0.0.1');
        \fclose($socket);

        return $port;
    }

    private function serveIssuer(string $issuer): int
    {
        $port = self::freePort();
        \file_put_contents("{$this->sandbox}/router.php", '<?php echo json_encode(["issuer" => getenv("TEST_ISSUER") ?: null]);');
        $this->server = new Process(['php', '-S', "127.0.0.1:{$port}", "{$this->sandbox}/router.php"], null, ['TEST_ISSUER' => \sprintf($issuer, $port)]);
        $this->server->start();

        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @\stream_socket_client("tcp://127.0.0.1:{$port}", $code, $error, 1.0);

            if ($connection !== false) {
                \fclose($connection);

                return $port;
            }

            \usleep(50_000);
        }

        throw new RuntimeException('The issuer server did not start');
    }
}
