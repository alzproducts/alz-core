<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

use App\DevTools\GitHooks\DeptracPrePushHook;
use App\DevTools\GitHooks\PestPrePushHook;
use App\DevTools\GitHooks\PHPArkitectPreCommitHook;
use App\DevTools\GitHooks\TLintPrePushHook;
use Igorsgm\GitHooks\Console\Commands\Hooks\LarastanPreCommitHook;
use Igorsgm\GitHooks\Console\Commands\Hooks\PintPreCommitHook;
use Symfony\Component\Process\Process;

/**
 * The pre-commit and pre-push hooks are registered, executable, call artisan, and `config/git-hooks.php` lists all six checks (COR-336).
 */
final class GitHooksInstalledCheck
{
    /** @var array<string, list<class-string>> */
    public const array EXPECTED_CHECKS = [
        'pre-commit' => [PintPreCommitHook::class, LarastanPreCommitHook::class, PHPArkitectPreCommitHook::class],
        'pre-push' => [PestPrePushHook::class, DeptracPrePushHook::class, TLintPrePushHook::class],
    ];

    /**
     * GitHub Actions sets CI=true. The verify containers never reach this check: they exclude the repo-static group.
     */
    public static function exempt(string|false $ci): bool
    {
        return $ci === 'true';
    }

    /**
     * @param array<mixed> $config the `git-hooks` config
     *
     * @return list<string>
     */
    public static function violations(string $repoRoot, array $config): array
    {
        $hooksDir = self::hooksDirectory($repoRoot);
        $violations = [];

        foreach (self::EXPECTED_CHECKS as $hook => $checks) {
            $violations = [...$violations, ...self::hookFileViolations($hooksDir, $hook)];

            $listed = \is_array($config[$hook] ?? null) ? $config[$hook] : [];

            foreach ($checks as $check) {
                if (!\in_array($check, $listed, true)) {
                    $violations[] = \sprintf('config/git-hooks.php does not list %s under %s. Restore it, then run `make install`.', $check, $hook);
                }
            }
        }

        return $violations;
    }

    /**
     * Resolved the way git does, so `core.hooksPath` and worktrees are honoured.
     */
    public static function hooksDirectory(string $repoRoot): string
    {
        $process = new Process(['git', 'rev-parse', '--path-format=absolute', '--git-path', 'hooks'], $repoRoot, self::cleanGitEnv());
        $process->mustRun();

        return \mb_rtrim($process->getOutput());
    }

    /**
     * intentional: git exports GIT_DIR and friends to a hook, and they would redirect every git call here to the repo being pushed.
     *
     * @return array<string, false>
     */
    public static function cleanGitEnv(): array
    {
        return ['GIT_DIR' => false, 'GIT_WORK_TREE' => false, 'GIT_INDEX_FILE' => false, 'GIT_COMMON_DIR' => false, 'GIT_PREFIX' => false];
    }

    /**
     * @return list<string>
     */
    private static function hookFileViolations(string $hooksDir, string $hook): array
    {
        $path = $hooksDir . '/' . $hook;

        if (!\is_file($path)) {
            return [\sprintf('Git hook %s is not registered (expected %s). Run `make install`.', $hook, $path)];
        }

        $violations = [];

        if (!\is_executable($path)) {
            $violations[] = \sprintf('Git hook %s is not executable (%s). Run `make install`.', $hook, $path);
        }

        $contents = (string) \file_get_contents($path);

        if (!\str_contains($contents, 'artisan git-hooks:' . $hook)) {
            $violations[] = \sprintf('Git hook %s does not call `artisan git-hooks:%s` (%s). Run `make install`.', $hook, $hook, $path);
        }

        return $violations;
    }
}
