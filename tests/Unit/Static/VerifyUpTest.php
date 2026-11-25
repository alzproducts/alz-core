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
use Tests\Unit\Static\Support\VerifyStubRun;

/**
 * `verify up` refuses an env file or a commit it cannot boot faithfully before any image build, and leaves no build source behind.
 */
#[CoversNothing]
#[Group('repo-static')]
final class VerifyUpTest extends TestCase
{
    private VerifyStubRun $run;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->run = new VerifyStubRun();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->run->remove();

        parent::tearDown();
    }

    #[Test]
    public function it_lists_every_key_with_a_bare_dollar_and_no_value(): void
    {
        $this->run->commit();
        $this->run->env("FAKE_SECRET=ab\$cd\$\nFINE=1\nOTHER_SECRET=\"x\$y\"\n");

        $up = $this->run->verify('up');
        $output = $up->getOutput() . $up->getErrorOutput();

        $this->assertRefused($up, 'FAKE_SECRET OTHER_SECRET');
        $this->assertStringNotContainsString('ab$cd$', $output);
        $this->assertStringNotContainsString('x$y', $output);
        $this->assertStringNotContainsString('FINE', $output);
        $this->assertStringContainsString('single-quote', $output);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bareDollars(): iterable
    {
        yield 'a reference nothing sets' => ["FAKE_SECRET=ab\${NOPE}cd\n", 'FAKE_SECRET'];
        yield 'a reference only a later line sets' => ["FAKE_SECRET=ab\${NOPE}cd\nNOPE=x\n", 'FAKE_SECRET'];
        yield 'a reference to itself' => ["FAKE_SECRET=\${FAKE_SECRET}\n", 'FAKE_SECRET'];
        yield 'a reference to a commented-out key' => ["#NOPE=x\nFAKE_SECRET=\${NOPE}\n", 'FAKE_SECRET'];
        yield 'a braceless reference to an earlier key' => ["A=x\nFAKE_SECRET=\$A\n", 'FAKE_SECRET'];
        yield 'a reference with a default' => ["A=x\nFAKE_SECRET=\${A:-b}\n", 'FAKE_SECRET'];
        yield 'a bare dollar after a good reference' => ["A=x\nFAKE_SECRET=\${A}\$b\n", 'FAKE_SECRET'];
        yield 'export and spaces' => ["  export FAKE_SECRET = a\$b\n", 'FAKE_SECRET'];
        yield 'a colon separator' => ["FAKE_SECRET: a\$b\n", 'FAKE_SECRET'];
        yield 'CRLF, no final newline' => ["A='x'\r\nFAKE_SECRET=a\$", 'FAKE_SECRET'];
        yield 'a hash inside the value' => ["FAKE_SECRET=a#\$b\n", 'FAKE_SECRET'];
        yield 'a later line of a double-quoted value' => ["FAKE_SECRET=\"a\nNOPE=\$b\n\"\n", 'FAKE_SECRET'];
        yield 'a key set only inside another value' => ["A='a\nNOPE=b\n'\nFAKE_SECRET=\${NOPE}\n", 'FAKE_SECRET'];
        yield 'after an escaped quote' => ["FAKE_SECRET=\"a\\\"\$b\"\n", 'FAKE_SECRET'];
        yield 'after a single-quoted value ending in an escaped quote' => ["A='a\\'\$b'\nFAKE_SECRET=a\$b\n", 'FAKE_SECRET'];
    }

    #[Test]
    #[DataProvider('bareDollars')]
    public function it_refuses_a_value_that_compose_would_change(string $env, string $key): void
    {
        $this->run->commit();
        $this->run->env($env);

        $up = $this->run->verify('up');

        $this->assertRefused($up, "interpolate: {$key}");
        $this->assertSame(1, \preg_match("/interpolate: {$key} ?$/m", $up->getErrorOutput()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deliveredValues(): iterable
    {
        yield 'single quotes and a reference to an earlier key' => ["FAKE_SECRET='ab\$cd\$'\nFAKE_NAME=abc\nFAKE_REF=\"\${FAKE_NAME}\"\n"];
        yield 'a multi-line single-quoted value' => ["A='a\nB=\$b\n'\nC=x\n"];
        yield 'comments' => ["# \$a it's \"\nA=x # \$a\nB=\"b\" # \$a\n"];
        yield 'a reference in an unquoted value' => ["A=x\nB=a\${A}b\n"];
    }

    #[Test]
    #[DataProvider('deliveredValues')]
    public function it_builds_with_an_env_file_that_compose_delivers_unchanged(string $env): void
    {
        $this->run->commit();
        $this->run->env($env);
        $this->run->knob('fail', 'build');

        $this->run->verify('up');

        $this->assertStringContainsString(' build app', $this->run->calls());
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string}>
     */
    public static function bootGaps(): iterable
    {
        yield 'no Dockerfile.verify' => [['Dockerfile.verify' => null], 'Dockerfile.verify'];
        yield 'no db:ping' => [['docker-entrypoint.sh' => "php artisan db:show\n"], 'db:ping'];
    }

    /**
     * @param array<string, string|null> $changes
     */
    #[Test]
    #[DataProvider('bootGaps')]
    public function it_refuses_a_commit_that_cannot_boot(array $changes, string $named): void
    {
        $this->run->commit($changes);

        $this->assertRefused($this->run->verify('up'), $named);
    }

    #[Test]
    public function a_refused_evidence_dir_can_be_used_again(): void
    {
        $this->run->commit();
        $this->run->env("FAKE_SECRET=a\$b\n");
        $this->assertRefused($this->run->verify('up'), 'FAKE_SECRET');
        $this->run->env("FAKE_SECRET='a\$b'\n");

        $up = $this->run->verify('up');

        $this->assertSame(0, $up->getExitCode(), $up->getErrorOutput());
    }

    #[Test]
    public function the_db_check_switch_is_stack_owned(): void
    {
        $this->run->commit();

        $up = $this->run->verify('up', '--env-override', 'SKIP_DB_CHECK=true');

        $this->assertSame(64, $up->getExitCode());
        $this->assertStringContainsString('stack-owned key SKIP_DB_CHECK', $up->getErrorOutput());
    }

    #[Test]
    public function down_removes_the_build_source_a_killed_up_left(): void
    {
        $this->run->commit();
        $this->run->knob('hang', 'build');
        $this->run->killDuringBuild('up');
        $this->assertDirectoryExists("{$this->run->evidence}/.verify/src");

        $down = $this->run->verify('down');

        $this->assertSame(0, $down->getExitCode(), $down->getErrorOutput());
        $this->assertDirectoryDoesNotExist("{$this->run->evidence}/.verify/src");
    }

    #[Test]
    public function a_failed_build_leaves_no_build_source(): void
    {
        $this->run->commit();
        $this->run->knob('fail', 'build');

        $up = $this->run->verify('up');

        $this->assertSame(1, $up->getExitCode());
        $this->assertStringContainsString('image build failed', $up->getErrorOutput());
        $this->assertDirectoryDoesNotExist("{$this->run->evidence}/.verify/src");
    }

    private function assertRefused(Process $up, string $named): void
    {
        $this->assertStringContainsString($named, $up->getErrorOutput());
        $this->assertSame(75, $up->getExitCode());
        $this->assertStringNotContainsString(' build ', $this->run->calls());
    }
}
