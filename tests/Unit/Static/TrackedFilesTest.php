<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\Static\Support\TrackedFiles;

/**
 * The listing the static checks scan fails closed when git does.
 */
#[CoversNothing]
#[Group('repo-static')]
final class TrackedFilesTest extends TestCase
{
    #[Test]
    public function it_lists_repo_files_and_leaves_out_ignored_ones(): void
    {
        $paths = TrackedFiles::list(\base_path());

        $this->assertContains('app/Models/User.php', $paths);
        $this->assertContains('composer.json', $paths);
        $this->assertNotContains('vendor/autoload.php', $paths);
    }

    #[Test]
    public function a_failing_git_command_throws_instead_of_returning_an_empty_list(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('git ls-files failed');

        TrackedFiles::list(\base_path(), ':(foo)x');
    }
}
