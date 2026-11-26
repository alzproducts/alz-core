<?php

declare(strict_types=1);

namespace Tests\Unit\DevTools\GitHooks;

use App\DevTools\GitHooks\TLintPrePushHook;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversNothing]
final class TLintPrePushHookTest extends TestCase
{
    public function test_it_runs_make_tlint_with_a_90_second_timeout(): void
    {
        $hook = new TLintPrePushHook();

        self::assertSame(['make', 'tlint'], new ReflectionMethod($hook, 'getProcessCommand')->invoke($hook));
        self::assertSame(90, new ReflectionMethod($hook, 'getTimeout')->invoke($hook));
    }

    // intentional: grouped although it reads no git. The verify test image ships no Makefile.
    #[Group('repo-static')]
    public function test_the_tlint_target_lints_only_the_app_and_routes_directories(): void
    {
        $makefile = (string) \file_get_contents(\dirname(__DIR__, 4) . '/Makefile');

        \preg_match('/^tlint:[^\n]*\n((?:\t[^\n]*\n)+)/m', $makefile, $target);

        self::assertSame(
            "\t@echo \"\$(MODE)\"\n\tvendor/bin/tlint lint app/ && vendor/bin/tlint lint routes/\n",
            $target[1] ?? null,
        );
    }
}
