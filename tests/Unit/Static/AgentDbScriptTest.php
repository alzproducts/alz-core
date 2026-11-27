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

/**
 * bin/agent-db against stub `security` and `psql` first on PATH; never the real Keychain item or production.
 */
#[CoversNothing]
#[Group('repo-static')]
final class AgentDbScriptTest extends TestCase
{
    private const string PASSWORD = 'stub-pw-7f3a9c1e';

    private const string SECURITY = <<<'SH'
        #!/bin/sh
        S="$(cd "$(dirname "$0")/.." && pwd -P)"
        echo "$@" >>"$S/security-calls"
        if [ -e "$S/missing" ]; then
            echo 'security: SecKeychainSearchCopyNext: The specified item could not be found in the keychain.' >&2
            exit 44
        fi
        for a in "$@"; do
            [ "$a" = -w ] && { cat "$S/password"; exit 0; }
        done
        echo 'keychain: "stub.keychain-db"'
        echo 'attributes:'
        echo '    "acct"<blob>="agent_readonly.stubref"'
        echo '    "icmt"<blob>="stub-pooler.example.test"'
        echo '    "svce"<blob>="alz-core-agent-db"'
        SH;

    private const string PSQL = <<<'SH'
        #!/bin/sh
        S="$(cd "$(dirname "$0")/.." && pwd -P)"
        for a in "$@"; do printf '%s\n' "$a"; done >"$S/psql-args"
        printf '%s' "${PGPASSWORD-}" >"$S/psql-pgpassword"
        echo ' answer'
        echo '--------'
        echo '      1'
        SH;

    private string $root;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->root = \sys_get_temp_dir() . '/agent-db-stub-' . \bin2hex(\random_bytes(6));
        \mkdir("{$this->root}/bin", 0o700, true);
        \mkdir("{$this->root}/home/.config/alz-core", 0o700, true);
        \file_put_contents("{$this->root}/home/.config/alz-core/supabase-root.crt", "stub certificate\n");
        \file_put_contents("{$this->root}/password", self::PASSWORD);
        \file_put_contents("{$this->root}/bin/security", self::SECURITY);
        \file_put_contents("{$this->root}/bin/psql", self::PSQL);
        \chmod("{$this->root}/bin/security", 0o755);
        \chmod("{$this->root}/bin/psql", 0o755);
    }

    #[Override]
    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->root]))->mustRun();

        parent::tearDown();
    }

    #[Test]
    public function a_select_passes_the_password_only_through_pgpassword_with_verify_full_ssl(): void
    {
        $run = $this->agentDb('-c', 'SELECT 1 AS answer');

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $this->assertSame(self::PASSWORD, \file_get_contents("{$this->root}/psql-pgpassword"));

        $args = (string) \file_get_contents("{$this->root}/psql-args");
        $this->assertStringNotContainsString(self::PASSWORD, $args);
        $this->assertStringContainsString('sslmode=verify-full', $args);
        $this->assertStringContainsString("sslrootcert={$this->root}/home/.config/alz-core/supabase-root.crt", $args);
        $this->assertStringContainsString('user=agent_readonly.stubref', $args);
        $this->assertStringContainsString('host=stub-pooler.example.test', $args);
        $this->assertStringContainsString("-c\nSELECT 1 AS answer\n", $args);

        $this->assertStringNotContainsString(self::PASSWORD, $run->getOutput());
        $this->assertStringNotContainsString(self::PASSWORD, $run->getErrorOutput());
        $this->assertStringContainsString('answer', $run->getOutput());
    }

    #[Test]
    public function a_missing_keychain_item_exits_non_zero_naming_it_without_running_psql(): void
    {
        \touch("{$this->root}/missing");

        $run = $this->agentDb('-c', 'SELECT 1');

        $this->assertNotSame(0, $run->getExitCode());
        $this->assertStringContainsString("Keychain item 'alz-core-agent-db'", $run->getErrorOutput());
        $this->assertFileDoesNotExist("{$this->root}/psql-args");
    }

    #[Test]
    public function anything_but_one_query_or_file_prints_usage_without_reading_the_keychain(): void
    {
        $run = $this->agentDb('--host', 'elsewhere');

        $this->assertSame(64, $run->getExitCode());
        $this->assertStringContainsString('Usage: bin/agent-db', $run->getErrorOutput());
        $this->assertFileDoesNotExist("{$this->root}/security-calls");
    }

    #[Test]
    #[DataProvider('metaCommands')]
    public function a_psql_meta_command_is_refused_without_reading_the_keychain(string $flag, string $sql): void
    {
        \file_put_contents("{$this->root}/query.sql", $sql);

        $run = $this->agentDb($flag, $flag === '-f' ? "{$this->root}/query.sql" : $sql);

        $this->assertSame(64, $run->getExitCode());
        $this->assertStringContainsString('psql meta-commands are refused', $run->getErrorOutput());
        $this->assertFileDoesNotExist("{$this->root}/security-calls");
        $this->assertFileDoesNotExist("{$this->root}/psql-args");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function metaCommands(): array
    {
        return [
            'shell escape' => ['-c', '\! printenv PGPASSWORD'],
            'after whitespace' => ['-c', "  \n\\copy (SELECT 1) TO PROGRAM 'sh'"],
            'file' => ['-f', "\\! printenv PGPASSWORD\nSELECT 1;\n"],
        ];
    }

    #[Test]
    public function a_sql_file_reaches_psql_as_one_query_string(): void
    {
        \file_put_contents("{$this->root}/query.sql", "SELECT 1 AS answer;\n\\! printenv PGPASSWORD\n");

        $run = $this->agentDb('-f', "{$this->root}/query.sql");

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $this->assertStringContainsString("-c\nSELECT 1 AS answer;\n\\! printenv PGPASSWORD\n", (string) \file_get_contents("{$this->root}/psql-args"));
        $this->assertStringNotContainsString("-f\n", (string) \file_get_contents("{$this->root}/psql-args"));
    }

    private function agentDb(string ...$args): Process
    {
        $process = new Process(
            [\base_path('bin/agent-db'), ...$args],
            $this->root,
            ['PATH' => "{$this->root}/bin:/usr/bin:/bin", 'HOME' => "{$this->root}/home", 'AGENT_DB_ROOT_CERT' => false],
        );
        $process->run();

        return $process;
    }
}
