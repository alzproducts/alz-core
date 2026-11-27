<?php

declare(strict_types=1);

namespace Tests\Unit\DevTools\GitHooks;

use App\DevTools\GitHooks\PestPrePushHook;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversNothing]
final class PestPrePushHookTest extends TestCase
{
    public function test_it_runs_make_test_changed_with_a_180_second_timeout(): void
    {
        $hook = new PestPrePushHook();

        self::assertSame(['make', 'test-changed'], new ReflectionMethod($hook, 'getProcessCommand')->invoke($hook));
        self::assertSame(180, new ReflectionMethod($hook, 'getTimeout')->invoke($hook));
    }

    // intentional: grouped although it reads no git. The verify test image ships no Makefile.
    #[Group('repo-static')]
    public function test_the_test_changed_target_runs_pest_on_a_generated_config_with_the_test_target_exclusions(): void
    {
        $makefile = (string) \file_get_contents(\dirname(__DIR__, 4) . '/Makefile');

        $pest = self::pestLine($makefile, 'test-changed');

        self::assertMatchesRegularExpression('/ vendor\/bin\/pest --configuration="\$\$config" /', $pest);
        foreach (['--parallel', '--processes=4', '--no-coverage', '--do-not-fail-on-empty-test-suite'] as $flag) {
            self::assertStringContainsString(' ' . $flag . ' ', $pest);
        }

        $excludes = static fn(string $line): array => \preg_match_all('/--exclude-group=[\w-]+/', $line, $m) > 0 ? $m[0] : [];
        self::assertCount(6, $excludes(self::pestLine($makefile, 'test')));
        self::assertSame($excludes(self::pestLine($makefile, 'test')), $excludes($pest));
    }

    private static function pestLine(string $makefile, string $target): string
    {
        \preg_match('/^' . \preg_quote($target, '/') . ':[^\n]*\n((?:\t[^\n]*\n)+)/m', $makefile, $recipe);
        \preg_match('/^.*vendor\/bin\/pest .*$/m', $recipe[1] ?? '', $line);

        return $line[0] ?? '';
    }
}
