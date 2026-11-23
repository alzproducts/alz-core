<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Static\Support\ApplicationSuiteDatabaseCheck;
use Tests\Unit\Static\Support\TrackedFiles;

/**
 * The `Application` testsuite runs without a database (`make mutate-app` in CI, #1089).
 */
#[CoversNothing]
#[Group('repo-static')]
final class ApplicationSuiteDatabaseTest extends TestCase
{
    private const string PLANTED = 'tests/Unit/Application/Planted/PlantedTest.php';

    #[Test]
    public function no_application_suite_test_needs_a_database(): void
    {
        $root = \base_path();

        $this->assertSame([], ApplicationSuiteDatabaseCheck::violations(
            TrackedFiles::list($root, ...ApplicationSuiteDatabaseCheck::DIRECTORIES),
            TrackedFiles::reader($root),
            ApplicationSuiteDatabaseCheck::reflectedTraits(),
        ));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function plantedReferences(): iterable
    {
        yield 'class trait' => ["use Illuminate\\Foundation\\Testing\\DatabaseTransactions;\n\nfinal class PlantedTest\n{\n    use DatabaseTransactions;\n}\n", 'DatabaseTransactions'];
        yield 'pest uses' => ["uses(\\Illuminate\\Foundation\\Testing\\RefreshDatabase::class);\n", 'RefreshDatabase'];
        yield 'grouped import' => ["use Illuminate\\Foundation\\Testing\\{LazilyRefreshDatabase};\n", 'LazilyRefreshDatabase'];
        yield 'migrations trait' => ["final class PlantedTest\n{\n    use \\Illuminate\\Foundation\\Testing\\DatabaseMigrations;\n}\n", 'DatabaseMigrations'];
        yield 'database assertion' => ["\$this->assertDatabaseHas('orders', ['id' => 1]);\n", 'assertDatabaseHas'];
    }

    #[Test]
    #[DataProvider('plantedReferences')]
    public function a_planted_reference_fails_the_check(string $code, string $name): void
    {
        $violations = ApplicationSuiteDatabaseCheck::violations(self::listing(), self::reader("<?php\n\nnamespace Tests\\Unit\\Application\\Planted;\n\n" . $code), self::noTraits());

        $this->assertSame([self::PLANTED . ' uses ' . $name], $violations);
    }

    #[Test]
    public function a_trait_inherited_from_a_base_class_fails_the_check(): void
    {
        $code = "<?php\n\nnamespace Tests\\Unit\\Application\\Planted;\n\nfinal class PlantedTest extends DatabaseTestCase\n{\n}\n";
        $traitsOf = static fn(string $class): array => $class === 'Tests\\Unit\\Application\\Planted\\PlantedTest' ? [RefreshDatabase::class] : [];

        $this->assertSame(
            [self::PLANTED . ' inherits RefreshDatabase in Tests\\Unit\\Application\\Planted\\PlantedTest'],
            ApplicationSuiteDatabaseCheck::violations(self::listing(), self::reader($code), $traitsOf),
        );
    }

    #[Test]
    public function a_directly_used_trait_is_reported_once(): void
    {
        $code = "<?php\n\nnamespace Tests\\Unit\\Application\\Planted;\n\nfinal class PlantedTest\n{\n    use \\Illuminate\\Foundation\\Testing\\DatabaseTransactions;\n}\n";
        $traitsOf = static fn(string $class): array => $class === 'Tests\\Unit\\Application\\Planted\\PlantedTest' ? [DatabaseTransactions::class] : [];

        $this->assertSame([self::PLANTED . ' uses DatabaseTransactions'], ApplicationSuiteDatabaseCheck::violations(self::listing(), self::reader($code), $traitsOf));
    }

    #[Test]
    public function names_in_comments_and_strings_and_mocked_gateways_are_not_references(): void
    {
        $code = "<?php\n\n// no DatabaseTransactions here\n\$label = 'RefreshDatabase';\n\$db = Mockery::mock(DatabaseGatewayInterface::class);\n";

        $this->assertSame([], ApplicationSuiteDatabaseCheck::violations(self::listing(), self::reader($code), self::noTraits()));
    }

    #[Test]
    public function a_file_outside_the_application_suite_is_not_scanned(): void
    {
        $listing = [...self::listing(), 'tests/Feature/Infrastructure/Planted/PlantedTest.php'];
        $reader = static fn(string $path): string => \str_starts_with($path, 'tests/Feature/Infrastructure/') ? "<?php\n\nuses(RefreshDatabase::class);\n" : "<?php\n";

        $this->assertSame([], ApplicationSuiteDatabaseCheck::violations($listing, $reader, self::noTraits()));
    }

    #[Test]
    public function an_empty_listing_fails_closed(): void
    {
        $this->assertSame(
            [
                'only 0 Application testsuite PHP files listed, expected at least ' . ApplicationSuiteDatabaseCheck::MIN_FILES,
                'known file missing from the listing: ' . ApplicationSuiteDatabaseCheck::KNOWN_FILE,
            ],
            ApplicationSuiteDatabaseCheck::violations([], self::reader(''), self::noTraits()),
        );
    }

    /**
     * @return list<string>
     */
    private static function listing(): array
    {
        $filler = \array_map(static fn(int $n): string => \sprintf('tests/Feature/Application/Filler/Filler%04dTest.php', $n), \range(1, ApplicationSuiteDatabaseCheck::MIN_FILES));

        return [ApplicationSuiteDatabaseCheck::KNOWN_FILE, self::PLANTED, ...$filler];
    }

    /**
     * @return callable(string): string serves `$planted` for the planted file and a DB-free test for the rest
     */
    private static function reader(string $planted): callable
    {
        return static fn(string $path): string => $path === self::PLANTED ? $planted : "<?php\n\nnamespace Tests\\Unit\\Application;\n\nuse PHPUnit\\Framework\\TestCase;\n";
    }

    /**
     * @return callable(string): list<string>
     */
    private static function noTraits(): callable
    {
        return static fn(string $class): array => [];
    }
}
