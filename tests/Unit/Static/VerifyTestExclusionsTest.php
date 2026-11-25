<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Static\Support\TrackedFiles;
use Tests\Unit\Static\Support\VerifyTestExclusionsCheck;

/**
 * `verify test` excludes the groups CI's test step excludes (COR-284 AC7). Needs `.github/`, which the verify sidecar lacks.
 */
#[CoversNothing]
#[Group('repo-static')]
final class VerifyTestExclusionsTest extends TestCase
{
    #[Test]
    public function the_verb_defaults_match_the_groups_ci_excludes(): void
    {
        $this->assertSame([], VerifyTestExclusionsCheck::violations(self::workflow(), self::verb()));
    }

    #[Test]
    public function a_group_only_in_ci_fails_the_check(): void
    {
        $workflow = \str_replace('--exclude-group=list-parity', '--exclude-group=list-parity --exclude-group=slow', self::workflow());

        $this->assertSame(['group only in CI: slow'], VerifyTestExclusionsCheck::violations($workflow, self::verb()));
    }

    #[Test]
    public function a_group_only_in_the_verb_fails_the_check(): void
    {
        $verb = \str_replace("TEST_DEFAULT_EXCLUDE_GROUPS='", "TEST_DEFAULT_EXCLUDE_GROUPS='slow ", self::verb());

        $this->assertSame(['group only in the verb: slow'], VerifyTestExclusionsCheck::violations(self::workflow(), $verb));
    }

    #[Test]
    public function a_missing_ci_step_or_verb_list_fails_the_check(): void
    {
        $this->assertSame(
            ['CI test step not found exactly once', 'verb TEST_DEFAULT_EXCLUDE_GROUPS not found'],
            VerifyTestExclusionsCheck::violations("jobs: {}\n", ''),
        );
    }

    private static function workflow(): string
    {
        return TrackedFiles::reader(\dirname(__DIR__, 3))(VerifyTestExclusionsCheck::WORKFLOW);
    }

    private static function verb(): string
    {
        return TrackedFiles::reader(\dirname(__DIR__, 3))(VerifyTestExclusionsCheck::VERB);
    }
}
