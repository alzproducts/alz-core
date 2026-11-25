<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * `phpunit.xml` excludes `list-parity` by default and `--group=list-parity` opts in (COR-284 AC1, AC2). Asserts Pest's selection
 * with `--list-tests`; the parity test body needs a capture that a fresh worktree lacks. Spawns Pest, so it stays out of the verify sidecar.
 */
#[CoversNothing]
#[Group('repo-static')]
final class ListParityDefaultExclusionTest extends TestCase
{
    private const string PARITY_FILE = 'tests/Integration/Catalog/ProductListParityTest.php';

    private const string PARITY_TEST = 'Tests\Integration\Catalog\ProductListParityTest::every_list_shape_matches_the_reference_with_an_empty_and_a_warm_fragment_table';

    #[Test]
    public function a_plain_run_of_the_parity_file_selects_no_test(): void
    {
        $listing = self::pest('--list-tests', self::PARITY_FILE);
        $run = self::pest('--no-coverage', self::PARITY_FILE);

        $this->assertSame(0, $listing->getExitCode());
        $this->assertStringNotContainsString(self::PARITY_TEST, $listing->getOutput());
        $this->assertSame(0, $run->getExitCode(), $run->getOutput() . $run->getErrorOutput());
    }

    #[Test]
    public function the_list_parity_group_selects_exactly_the_parity_test(): void
    {
        $listing = self::pest('--list-tests', '--group=list-parity');

        $this->assertSame(0, $listing->getExitCode());
        $this->assertSame(
            [self::PARITY_TEST],
            \array_values(\array_map(
                static fn(string $line): string => \mb_rtrim(\mb_ltrim($line, "- \t"), '.'),
                \array_filter(\explode("\n", $listing->getOutput()), static fn(string $line): bool => \str_contains($line, '::')),
            )),
        );
    }

    private static function pest(string ...$arguments): Process
    {
        $process = new Process([\PHP_BINARY, 'vendor/bin/pest', '--colors=never', ...$arguments], \dirname(__DIR__, 3));
        $process->setTimeout(120);
        $process->run();

        return $process;
    }
}
