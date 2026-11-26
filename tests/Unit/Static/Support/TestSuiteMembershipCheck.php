<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

use DOMDocument;
use DOMXPath;

/**
 * Every test file is inside a path an active `phpunit.xml` testsuite lists, so CI's plain Pest run includes it.
 */
final class TestSuiteMembershipCheck
{
    public const string PHPUNIT_XML = 'phpunit.xml';

    public const string TEST_FILES = 'tests/*Test.php';

    /** Its suite is commented out in `phpunit.xml`; re-enabling it is tracked in `todo.php`. */
    public const string ALLOWED_EXCEPTION = 'tests/ArchitectureTest.php';

    /**
     * @param list<string> $paths repo-relative test file paths
     * @param non-empty-string $phpunitXml contents of `phpunit.xml`
     *
     * @return list<string>
     */
    public static function violations(array $paths, string $phpunitXml): array
    {
        if ($paths === []) {
            return ['the file listing is empty'];
        }

        $suitePaths = self::suitePaths($phpunitXml);
        $violations = [];

        foreach ($paths as $path) {
            if ($path !== self::ALLOWED_EXCEPTION && !self::isListed($path, $suitePaths)) {
                $violations[] = 'test file in no phpunit testsuite: ' . $path;
            }
        }

        return $violations;
    }

    /**
     * Parsed rather than searched, so a commented-out suite is not listed.
     *
     * @param non-empty-string $phpunitXml
     *
     * @return list<string>
     */
    private static function suitePaths(string $phpunitXml): array
    {
        $document = new DOMDocument();
        $document->loadXML($phpunitXml);
        $nodes = new DOMXPath($document)->query('/phpunit/testsuites/testsuite/*[self::directory or self::file]');
        $paths = [];

        foreach ($nodes === false ? [] : $nodes as $node) {
            $paths[] = \mb_rtrim(\mb_trim($node->textContent), '/');
        }

        return $paths;
    }

    /**
     * @param list<string> $suitePaths
     */
    private static function isListed(string $path, array $suitePaths): bool
    {
        return \array_any(
            $suitePaths,
            static fn(string $suitePath): bool => $path === $suitePath || \str_starts_with($path, $suitePath . '/'),
        );
    }
}
