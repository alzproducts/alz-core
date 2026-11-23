<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Static\Support\GitHooksInstalledCheck;

/**
 * The pre-commit and pre-push hooks are switched on in this checkout. Fix: `make install` (COR-336).
 */
#[CoversNothing]
#[Group('repo-static')]
final class GitHooksInstalledTest extends TestCase
{
    private string $sandbox = '';

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->sandbox !== '') {
            (new Process(['rm', '-rf', $this->sandbox]))->mustRun();
        }

        parent::tearDown();
    }

    #[Test]
    public function the_hooks_are_registered_in_this_checkout(): void
    {
        if (GitHooksInstalledCheck::exempt(\getenv('CI'))) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertSame(
            [],
            GitHooksInstalledCheck::violations(\dirname(__DIR__, 3), self::hooksConfig()),
            'Git hooks are off. Run `make install`.',
        );
    }

    #[Test]
    public function only_the_ci_variable_set_to_true_exempts_the_check(): void
    {
        $this->assertTrue(GitHooksInstalledCheck::exempt('true'));
        $this->assertFalse(GitHooksInstalledCheck::exempt('false'));
        $this->assertFalse(GitHooksInstalledCheck::exempt('1'));
        $this->assertFalse(GitHooksInstalledCheck::exempt(''));
        $this->assertFalse(GitHooksInstalledCheck::exempt(false));
    }

    #[Test]
    public function a_fully_registered_repo_passes(): void
    {
        $repo = $this->repoWithHooks();

        $this->assertSame([], GitHooksInstalledCheck::violations($repo, self::hooksConfig()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hooks(): iterable
    {
        yield 'pre-commit' => ['pre-commit'];
        yield 'pre-push' => ['pre-push'];
    }

    #[Test]
    #[DataProvider('hooks')]
    public function a_missing_hook_fails_and_names_make_install(string $hook): void
    {
        $repo = $this->repoWithHooks();
        \unlink(GitHooksInstalledCheck::hooksDirectory($repo) . '/' . $hook);

        $violations = GitHooksInstalledCheck::violations($repo, self::hooksConfig());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Git hook ' . $hook . ' is not registered', $violations[0]);
        $this->assertStringContainsString('`make install`', $violations[0]);
    }

    #[Test]
    #[DataProvider('hooks')]
    public function a_hook_that_is_not_executable_fails_and_names_make_install(string $hook): void
    {
        $repo = $this->repoWithHooks();
        \chmod(GitHooksInstalledCheck::hooksDirectory($repo) . '/' . $hook, 0644);

        $violations = GitHooksInstalledCheck::violations($repo, self::hooksConfig());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Git hook ' . $hook . ' is not executable', $violations[0]);
        $this->assertStringContainsString('`make install`', $violations[0]);
    }

    #[Test]
    #[DataProvider('hooks')]
    public function a_hook_that_does_not_call_artisan_fails_and_names_make_install(string $hook): void
    {
        $repo = $this->repoWithHooks();
        \file_put_contents(GitHooksInstalledCheck::hooksDirectory($repo) . '/' . $hook, "#!/bin/sh\nexit 0\n");

        $violations = GitHooksInstalledCheck::violations($repo, self::hooksConfig());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('does not call `artisan git-hooks:' . $hook . '`', $violations[0]);
        $this->assertStringContainsString('`make install`', $violations[0]);
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function checks(): iterable
    {
        foreach (GitHooksInstalledCheck::EXPECTED_CHECKS as $hook => $classes) {
            foreach ($classes as $class) {
                yield $hook . ' without ' . $class => [$hook, $class];
            }
        }
    }

    /**
     * @param class-string $check
     */
    #[Test]
    #[DataProvider('checks')]
    public function a_config_without_one_of_the_six_checks_fails_and_names_make_install(string $hook, string $check): void
    {
        $config = self::hooksConfig();
        /** @var list<class-string> $listed */
        $listed = $config[$hook];
        $config[$hook] = \array_values(\array_filter($listed, static fn(string $class): bool => $class !== $check));

        $violations = GitHooksInstalledCheck::violations($this->repoWithHooks(), $config);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString($check . ' under ' . $hook, $violations[0]);
        $this->assertStringContainsString('`make install`', $violations[0]);
    }

    #[Test]
    public function a_core_hooks_path_setting_is_honoured(): void
    {
        $repo = $this->repoWithHooks();
        $this->git($repo, 'config', 'core.hooksPath', 'custom-hooks');
        \mkdir($repo . '/custom-hooks');

        $this->assertCount(2, GitHooksInstalledCheck::violations($repo, self::hooksConfig()));

        \rename($repo . '/.git/hooks/pre-commit', $repo . '/custom-hooks/pre-commit');
        \rename($repo . '/.git/hooks/pre-push', $repo . '/custom-hooks/pre-push');

        $this->assertSame([], GitHooksInstalledCheck::violations($repo, self::hooksConfig()));
    }

    #[Test]
    public function a_worktree_resolves_to_the_hooks_of_the_main_repo(): void
    {
        $repo = $this->repoWithHooks();
        $worktree = $this->sandbox . '/worktree';
        $this->git($repo, 'worktree', 'add', '-b', 'wt', $worktree);

        $this->assertSame(
            \realpath($repo . '/.git/hooks'),
            \realpath(GitHooksInstalledCheck::hooksDirectory($worktree)),
        );
        $this->assertSame([], GitHooksInstalledCheck::violations($worktree, self::hooksConfig()));
    }

    /**
     * @return array<string, mixed>
     */
    private static function hooksConfig(): array
    {
        /** @var array<string, mixed> */
        return [
            'pre-commit' => \config('git-hooks.pre-commit'),
            'pre-push' => \config('git-hooks.pre-push'),
        ];
    }

    /**
     * A throwaway git repo whose pre-commit and pre-push look like the ones `make install` writes.
     */
    private function repoWithHooks(): string
    {
        $this->sandbox = \sys_get_temp_dir() . '/git-hooks-check-' . \bin2hex(\random_bytes(4));
        $repo = $this->sandbox . '/repo';
        \mkdir($repo, 0777, true);
        $this->git($repo, 'init', '--quiet', '--initial-branch=main');
        $this->git($repo, 'config', 'user.email', 'test@example.invalid');
        $this->git($repo, 'config', 'user.name', 'Test');
        $this->git($repo, 'commit', '--quiet', '--allow-empty', '-m', 'init');

        foreach (['pre-commit', 'pre-push'] as $hook) {
            $path = $repo . '/.git/hooks/' . $hook;
            \file_put_contents($path, "#!/bin/sh\nphp artisan git-hooks:" . $hook . " \"\$@\" >&2\n");
            \chmod($path, 0755);
        }

        return $repo;
    }

    private function git(string $cwd, string ...$args): void
    {
        (new Process(['git', ...$args], $cwd, GitHooksInstalledCheck::cleanGitEnv()))->mustRun();
    }
}
