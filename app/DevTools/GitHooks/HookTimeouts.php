<?php

declare(strict_types=1);

namespace App\DevTools\GitHooks;

/**
 * Seconds each git hook may run before it is killed. Sized for a loaded machine, not an idle one: several worktrees,
 * Docker builds and Playwright runs at once are normal. Aim for 2-3x the slowest loaded runtime.
 */
final class HookTimeouts
{
    /** 253s for 112 changed tests at load 60-80. */
    public const int PEST_SECONDS = 600;

    // The runtimes below were measured at load 20-25, so each limit also doubles for the heavier peak.

    /** `make tlint`: 37s. */
    public const int TLINT_SECONDS = 180;

    /** `make deptrac`: 27s on its first run. */
    public const int DEPTRAC_SECONDS = 120;

    /** `composer phparkitect`: 15s on its first run. */
    public const int PHPARKITECT_SECONDS = 90;
}
