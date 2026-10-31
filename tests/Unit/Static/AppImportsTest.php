<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Static\Support\AppImportCheck;
use Tests\Unit\Static\Support\TrackedFiles;

/**
 * No class under `app/` references Faker or the `database/` factories and seeders (AC14).
 */
#[CoversNothing]
#[Group('repo-static')]
final class AppImportsTest extends TestCase
{
    private const string PLANTED = 'app/Infrastructure/Planted/PlantedClient.php';

    #[Test]
    public function app_references_neither_faker_nor_the_database_generators(): void
    {
        $root = \base_path();

        $this->assertSame([], AppImportCheck::violations(TrackedFiles::list($root, 'app'), TrackedFiles::reader($root)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function plantedReferences(): iterable
    {
        yield 'faker import' => ["use Faker\\Generator;\n", 'Faker\\'];
        yield 'factory import' => ["use Database\\Factories\\ProductSlice\\ProductRowFactory;\n", 'Database\\Factories\\'];
        yield 'seeder import' => ["use Database\\Seeders\\DatabaseSeeder;\n", 'Database\\Seeders\\'];
        yield 'grouped import' => ["use Database\\{Seeders\\DatabaseSeeder};\n", 'Database\\{'];
        yield 'fully-qualified faker' => ["\$faker = \\Faker\\Factory::create();\n", '\\Faker\\'];
        yield 'fully-qualified seeder' => ["new \\Database\\Seeders\\ProductSlice\\ProfileSliceGenerator();\n", '\\Database\\Seeders\\'];
    }

    #[Test]
    #[DataProvider('plantedReferences')]
    public function a_planted_reference_fails_the_check(string $line, string $reference): void
    {
        $violations = AppImportCheck::violations(self::listing(), self::reader("<?php\n\nnamespace App\\Infrastructure\\Planted;\n\n" . $line));

        $this->assertSame([self::PLANTED . ' references ' . $reference], $violations);
    }

    #[Test]
    public function a_namespace_merely_ending_in_seeders_is_not_a_reference(): void
    {
        $content = "<?php\n\nuse App\\Infrastructure\\Database\\Seeders\\Thing;\nuse App\\Faker\\Thing as Other;\n";

        $this->assertSame([], AppImportCheck::violations(self::listing(), self::reader($content)));
    }

    #[Test]
    public function an_empty_listing_fails_closed(): void
    {
        $this->assertSame(
            [
                'only 0 app/ PHP files listed, expected at least ' . AppImportCheck::MIN_APP_FILES,
                'known file missing from the listing: app/Models/User.php',
            ],
            AppImportCheck::violations([], self::reader('')),
        );
    }

    #[Test]
    public function a_listing_without_the_known_file_fails_closed(): void
    {
        $listing = \array_values(\array_diff(self::listing(), [AppImportCheck::KNOWN_FILE]));

        $this->assertSame(['known file missing from the listing: app/Models/User.php'], AppImportCheck::violations($listing, self::reader('')));
    }

    /**
     * @return list<string>
     */
    private static function listing(): array
    {
        $filler = \array_map(static fn(int $n): string => \sprintf('app/Domain/Filler/Filler%04d.php', $n), \range(1, AppImportCheck::MIN_APP_FILES));

        return [AppImportCheck::KNOWN_FILE, self::PLANTED, 'database/seeders/DatabaseSeeder.php', ...$filler];
    }

    /**
     * @return callable(string): string serves `$planted` for the planted file and a clean class for the rest
     */
    private static function reader(string $planted): callable
    {
        return static fn(string $path): string => $path === self::PLANTED ? $planted : "<?php\n\nnamespace App\\Domain;\n\nuse App\\Domain\\ValueObjects\\Uuid;\n";
    }
}
