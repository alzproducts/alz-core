<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpToken;

/**
 * No test in the phpunit `Application` testsuite needs a database: `make mutate-app` runs that suite in CI with no
 * Postgres (#1089). Flags a Laravel database testing trait referenced in the file (`use` in a class, Pest `uses()`,
 * an import), one a declared class inherits from a parent or trait, and the Laravel database assertions.
 */
final class ApplicationSuiteDatabaseCheck
{
    /** @var list<string> mirrors the `Application` testsuite in phpunit.xml */
    public const array DIRECTORIES = ['tests/Unit/Application', 'tests/Feature/Application'];

    /** Well below the tracked Application test file count, so a broken file listing cannot pass as "no DB tests". */
    public const int MIN_FILES = 100;

    public const string KNOWN_FILE = 'tests/Feature/Application/Auth/TestUserPersonaResolverTest.php';

    /** @var list<class-string> */
    public const array DATABASE_TRAITS = [
        DatabaseMigrations::class,
        DatabaseTransactions::class,
        DatabaseTruncation::class,
        LazilyRefreshDatabase::class,
        RefreshDatabase::class,
    ];

    /** @var list<string> InteractsWithDatabase assertions, each of which queries the connection */
    public const array DATABASE_ASSERTIONS = [
        'assertDatabaseCount',
        'assertDatabaseEmpty',
        'assertDatabaseHas',
        'assertDatabaseMissing',
        'assertModelExists',
        'assertModelMissing',
        'assertNotSoftDeleted',
        'assertSoftDeleted',
    ];

    /**
     * @param list<string> $paths repo-relative paths
     * @param callable(string): string $read
     * @param callable(string): list<string> $traitsOf every trait a class name uses, inherited ones included
     *
     * @return list<string>
     */
    public static function violations(array $paths, callable $read, callable $traitsOf): array
    {
        $files = \array_values(\array_filter($paths, self::inApplicationSuite(...)));
        $violations = [];

        if (\count($files) < self::MIN_FILES) {
            $violations[] = \sprintf('only %d Application testsuite PHP files listed, expected at least %d', \count($files), self::MIN_FILES);
        }

        if (!\in_array(self::KNOWN_FILE, $files, true)) {
            $violations[] = 'known file missing from the listing: ' . self::KNOWN_FILE;
        }

        foreach ($files as $path) {
            \array_push($violations, ...self::fileViolations($path, $read($path), $traitsOf));
        }

        return $violations;
    }

    /**
     * @return callable(string): list<string>
     */
    public static function reflectedTraits(): callable
    {
        return static fn(string $class): array => \class_exists($class) ? \array_values(\class_uses_recursive($class)) : [];
    }

    private static function inApplicationSuite(string $path): bool
    {
        return \str_ends_with($path, '.php')
            && \array_any(self::DIRECTORIES, static fn(string $directory): bool => \str_starts_with($path, $directory . '/'));
    }

    /**
     * @param callable(string): list<string> $traitsOf
     *
     * @return list<string>
     */
    private static function fileViolations(string $path, string $contents, callable $traitsOf): array
    {
        $traitNames = \array_map(static fn(string $trait): string => \class_basename($trait), self::DATABASE_TRAITS);
        $referenced = [];
        $namespace = '';
        $classes = [];
        $tokens = PhpToken::tokenize($contents);

        foreach ($tokens as $index => $token) {
            if ($token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                $name = \class_basename($token->text);

                if (\in_array($name, $traitNames, true) || \in_array($name, self::DATABASE_ASSERTIONS, true)) {
                    $referenced[$name] = true;
                }
            }

            if ($token->is(\T_NAMESPACE)) {
                $namespace = self::nextName($tokens, $index) ?? '';
            }

            if ($token->is(\T_CLASS) && !self::isClassConstant($tokens, $index)) {
                $class = self::nextName($tokens, $index);

                if ($class !== null) {
                    $classes[] = \mb_ltrim($namespace . '\\' . $class, '\\');
                }
            }
        }

        $violations = \array_map(static fn(string $name): string => $path . ' uses ' . $name, \array_keys($referenced));

        foreach ($classes as $class) {
            foreach (\array_intersect($traitsOf($class), self::DATABASE_TRAITS) as $trait) {
                if (!isset($referenced[\class_basename($trait)])) {
                    $violations[] = $path . ' inherits ' . \class_basename($trait) . ' in ' . $class;
                }
            }
        }

        return $violations;
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private static function nextName(array $tokens, int $index): ?string
    {
        $next = self::nextSignificant($tokens, $index);

        return $next?->is([\T_STRING, \T_NAME_QUALIFIED]) === true ? $next->text : null;
    }

    /**
     * `Foo::class` and anonymous `new class` are not declarations of a named class.
     *
     * @param list<PhpToken> $tokens
     */
    private static function isClassConstant(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (!$tokens[$i]->isIgnorable()) {
                return $tokens[$i]->is([\T_DOUBLE_COLON, \T_NEW]);
            }
        }

        return false;
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private static function nextSignificant(array $tokens, int $index): ?PhpToken
    {
        for ($i = $index + 1, $count = \count($tokens); $i < $count; $i++) {
            if (!$tokens[$i]->isIgnorable()) {
                return $tokens[$i];
            }
        }

        return null;
    }
}
