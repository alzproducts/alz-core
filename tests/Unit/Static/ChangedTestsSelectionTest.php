<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Static\Support\GitHooksInstalledCheck;

/**
 * `bin/changed-tests` and `make test-changed`, run in a throwaway repo whose origin/HEAD points at a base commit.
 */
#[CoversNothing]
#[Group('repo-static')]
final class ChangedTestsSelectionTest extends TestCase
{
    private string $sandbox = '';

    private string $repo = '';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = \sys_get_temp_dir() . '/changed-tests-' . \bin2hex(\random_bytes(6));
        $this->repo = $this->sandbox . '/repo';
        \mkdir($this->repo, 0o700, true);

        $this->git('init', '--quiet', '--initial-branch=feature');
        $this->git('config', 'user.email', 'test@example.invalid');
        $this->git('config', 'user.name', 'Test');
        $this->git('config', 'commit.gpgsign', 'false');
        $this->git('remote', 'add', 'origin', $this->sandbox . '/origin.git');

        $this->write('README.md', "base\n");
        $this->write('app/X/Bar.php', "<?php\n");
        $this->write('tests/B/BarTest.php', "<?php\n");
        $this->write('tests/C/GoneTest.php', "<?php\n");
        $this->write('tests/T/TrunkOnlyTest.php', "<?php\n");
        $this->commit('base');
        $this->git('update-ref', 'refs/remotes/origin/develop', 'HEAD');
        $this->git('symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/develop');

        $root = \dirname(__DIR__, 3);
        \mkdir($this->repo . '/bin');
        foreach (['changed-tests', 'worktree-db'] as $script) {
            \copy($root . "/bin/{$script}", $this->repo . "/bin/{$script}");
            \chmod($this->repo . "/bin/{$script}", 0o755);
        }
        \copy($root . '/Makefile', $this->repo . '/Makefile');
        \copy($root . '/phpunit.xml', $this->repo . '/phpunit.xml');
        $this->write('vendor/bin/pest', <<<'PHP'
            <?php
            $root = dirname(__DIR__, 2);
            file_put_contents($root . '/pest-args', implode("\n", array_slice($argv, 1)));
            foreach ($argv as $arg) {
                if (str_starts_with($arg, '--configuration=')) {
                    copy(substr($arg, strlen('--configuration=')), $root . '/pest-config.xml');
                }
            }
            PHP);
        $this->write('.git/info/exclude', "/bin/\n/Makefile\n/phpunit.xml\n/vendor/\n/pest-*\n");
    }

    #[Override]
    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->sandbox]))->mustRun();

        parent::tearDown();
    }

    #[Test]
    public function it_selects_added_and_basename_matched_tests_from_the_merge_base_only(): void
    {
        $this->git('checkout', '--quiet', '-b', 'trunk');
        $this->write('tests/T/TrunkOnlyTest.php', "<?php // trunk\n");
        $this->commit('trunk only');
        $this->git('update-ref', 'refs/remotes/origin/develop', 'HEAD');
        $this->git('checkout', '--quiet', 'feature');

        $this->write('tests/A/FooTest.php', "<?php\n");
        $this->write('app/X/Bar.php', "<?php // changed\n");
        \unlink($this->repo . '/tests/C/GoneTest.php');
        $this->commit('branch');

        $process = $this->runInRepo(['bin/changed-tests']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("tests/A/FooTest.php\ntests/B/BarTest.php\n", $process->getOutput());
    }

    #[Test]
    public function make_test_changed_skips_pest_when_no_test_changed(): void
    {
        $this->write('README.md', "changed\n");
        $this->commit('docs');

        $process = $this->runInRepo(['make', 'test-changed']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('No changed tests selected', $process->getOutput());
        $this->assertStringContainsString('CI runs the full suite', $process->getOutput());
        $this->assertFileDoesNotExist($this->repo . '/pest-args');
    }

    #[Test]
    public function make_test_changed_skips_pest_above_100_selected_files(): void
    {
        for ($i = 1; $i <= 101; $i++) {
            $this->write("tests/Many/Many{$i}Test.php", "<?php\n");
        }
        $this->commit('many');

        $process = $this->runInRepo(['make', 'test-changed']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('101 changed test files', $process->getOutput());
        $this->assertStringContainsString('CI runs the full suite', $process->getOutput());
        $this->assertFileDoesNotExist($this->repo . '/pest-args');
    }

    #[Test]
    public function make_test_changed_hands_the_selection_to_pest_as_one_generated_config(): void
    {
        $this->write('tests/A/FooTest.php', "<?php\n");
        $this->write('app/X/Bar.php', "<?php // changed\n");
        $this->commit('branch');

        $process = $this->runInRepo(['make', 'test-changed']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $args = (string) \file_get_contents($this->repo . '/pest-args');
        $this->assertStringNotContainsString('tests/', $args);
        $this->assertStringContainsString("--parallel\n--processes=4\n", $args);

        $config = (string) \file_get_contents($this->repo . '/pest-config.xml');
        \preg_match('#<testsuites>.*</testsuites>#s', $config, $suites);
        \preg_match_all('#<file>([^<]+)</file>#', $suites[0] ?? '', $files);
        $this->assertSame(['tests/A/FooTest.php', 'tests/B/BarTest.php'], $files[1]);
        $this->assertSame(1, \mb_substr_count($config, '<testsuite '));
        $this->assertStringContainsString('bootstrap="vendor/autoload.php"', $config);
        $this->assertSame([], \glob($this->repo . '/phpunit-changed.*'));
    }

    #[Test]
    public function the_script_fails_and_names_the_fix_without_origin_head(): void
    {
        $this->git('remote', 'set-head', 'origin', '--delete');

        $process = $this->runInRepo(['bin/changed-tests']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('git remote set-head origin --auto', $process->getErrorOutput());
    }

    #[Test]
    public function make_test_changed_fails_and_names_the_fix_without_origin_head(): void
    {
        $this->git('remote', 'set-head', 'origin', '--delete');

        $process = $this->runInRepo(['make', 'test-changed']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('git remote set-head origin --auto', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->repo . '/pest-args');
    }

    private function write(string $path, string $contents): void
    {
        $file = $this->repo . '/' . $path;
        if (! \is_dir(\dirname($file))) {
            \mkdir(\dirname($file), 0o700, true);
        }
        \file_put_contents($file, $contents);
    }

    private function commit(string $message): void
    {
        $this->git('add', '--all');
        $this->git('commit', '--quiet', '-m', $message);
    }

    private function git(string ...$args): void
    {
        (new Process(['git', ...$args], $this->repo, GitHooksInstalledCheck::cleanGitEnv()))->mustRun();
    }

    /**
     * @param list<string> $command
     */
    private function runInRepo(array $command): Process
    {
        $process = new Process($command, $this->repo, GitHooksInstalledCheck::cleanGitEnv(), null, 60);
        $process->run();

        return $process;
    }
}
