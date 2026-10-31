<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

/**
 * AC14: nothing under `app/` references Faker (a require-dev package) or the `database/` factories and seeders,
 * whether by `use` statement, grouped `use`, or a fully-qualified name.
 */
final class AppImportCheck
{
    /** Well below the tracked `app/` PHP file count, so a broken file listing cannot pass as "no imports". */
    public const int MIN_APP_FILES = 1000;

    public const string KNOWN_FILE = 'app/Models/User.php';

    private const string FORBIDDEN = '/(?<![\w\\\\])\\\\?(?:Faker\\\\|Database\\\\(?:Factories|Seeders)\\\\|Database\\\\\{)/i';

    /**
     * @param list<string> $paths repo-relative paths
     * @param callable(string): string $read
     *
     * @return list<string>
     */
    public static function violations(array $paths, callable $read): array
    {
        $appFiles = \array_values(\array_filter($paths, static fn(string $path): bool => \str_starts_with($path, 'app/') && \str_ends_with($path, '.php')));
        $violations = [];

        if (\count($appFiles) < self::MIN_APP_FILES) {
            $violations[] = \sprintf('only %d app/ PHP files listed, expected at least %d', \count($appFiles), self::MIN_APP_FILES);
        }

        if (!\in_array(self::KNOWN_FILE, $appFiles, true)) {
            $violations[] = 'known file missing from the listing: ' . self::KNOWN_FILE;
        }

        foreach ($appFiles as $path) {
            if (\preg_match_all(self::FORBIDDEN, $read($path), $matches) > 0) {
                $violations[] = $path . ' references ' . \implode(', ', \array_unique($matches[0]));
            }
        }

        return $violations;
    }
}
