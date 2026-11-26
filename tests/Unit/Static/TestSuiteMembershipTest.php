<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\Static\Support\TestSuiteMembershipCheck;
use Tests\Unit\Static\Support\TrackedFiles;

/**
 * A test directory that no `phpunit.xml` testsuite lists cannot be skipped in CI unnoticed (COR-364).
 */
#[CoversNothing]
#[Group('repo-static')]
final class TestSuiteMembershipTest extends TestCase
{
    #[Test]
    public function every_test_file_is_in_an_active_testsuite(): void
    {
        $this->assertSame([], TestSuiteMembershipCheck::violations(self::testFiles(), self::phpunitXml()));
    }

    #[Test]
    public function the_architecture_test_is_the_only_allowed_exception(): void
    {
        $this->assertSame('tests/ArchitectureTest.php', TestSuiteMembershipCheck::ALLOWED_EXCEPTION);
        $this->assertContains(TestSuiteMembershipCheck::ALLOWED_EXCEPTION, self::testFiles());
    }

    #[Test]
    public function a_planted_test_file_in_an_unlisted_directory_fails_the_check(): void
    {
        $this->assertSame(
            ['test file in no phpunit testsuite: tests/Feature/Unlisted/ExampleTest.php'],
            TestSuiteMembershipCheck::violations([...self::testFiles(), 'tests/Feature/Unlisted/ExampleTest.php'], self::phpunitXml()),
        );
    }

    #[Test]
    public function a_directory_sharing_a_listed_name_prefix_fails_the_check(): void
    {
        $this->assertSame(
            ['test file in no phpunit testsuite: tests/Feature/ProvidersExtra/ExampleTest.php'],
            TestSuiteMembershipCheck::violations(['tests/Feature/ProvidersExtra/ExampleTest.php'], self::phpunitXml()),
        );
    }

    #[Test]
    public function a_suite_omitting_a_directory_fails_for_each_test_file_under_it(): void
    {
        $files = \array_values(\array_filter(
            self::testFiles(),
            static fn(string $path): bool => \str_starts_with($path, 'tests/Feature/Providers/'),
        ));
        $this->assertNotSame([], $files);

        $this->assertSame(
            \array_map(static fn(string $path): string => 'test file in no phpunit testsuite: ' . $path, $files),
            TestSuiteMembershipCheck::violations(
                self::testFiles(),
                self::phpunitXmlWith('<directory>tests/Feature/Providers</directory>', ''),
            ),
        );
    }

    #[Test]
    public function a_commented_out_path_is_not_listed(): void
    {
        $entry = '<directory>tests/Feature/Providers</directory>';

        $this->assertSame(
            ['test file in no phpunit testsuite: tests/Feature/Providers/ExampleTest.php'],
            TestSuiteMembershipCheck::violations(
                ['tests/Feature/Providers/ExampleTest.php'],
                self::phpunitXmlWith($entry, '<!-- ' . $entry . ' -->'),
            ),
        );
    }

    #[Test]
    public function an_empty_listing_fails_closed(): void
    {
        $this->assertSame(['the file listing is empty'], TestSuiteMembershipCheck::violations([], self::phpunitXml()));
    }

    /**
     * @return list<string>
     */
    private static function testFiles(): array
    {
        return TrackedFiles::list(\base_path(), TestSuiteMembershipCheck::TEST_FILES);
    }

    /**
     * @return non-empty-string
     */
    private static function phpunitXml(): string
    {
        $xml = TrackedFiles::reader(\base_path())(TestSuiteMembershipCheck::PHPUNIT_XML);

        return $xml !== '' ? $xml : throw new RuntimeException('phpunit.xml is empty');
    }

    /**
     * @return non-empty-string
     */
    private static function phpunitXmlWith(string $search, string $replacement): string
    {
        $xml = \str_replace($search, $replacement, self::phpunitXml(), $count);

        return $count === 1 && $xml !== '' ? $xml : throw new RuntimeException('phpunit.xml does not hold exactly one: ' . $search);
    }
}
