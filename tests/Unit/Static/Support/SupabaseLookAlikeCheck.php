<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

/**
 * No migration creates a Supabase-owned object: the `auth` schema or anything in it, or a Supabase role (AC6).
 * Every environment alz-core migrates runs the Supabase baseline, which owns them.
 */
final class SupabaseLookAlikeCheck
{
    public const string MIGRATIONS_DIR = 'database/migrations/';

    private const string ROLES = '(?:anon|authenticated|service_role|supabase_auth_admin)';

    /**
     * @var array<string, non-empty-string> violation label => case-insensitive, line-spanning pattern
     */
    private const array PATTERNS = [
        'auth schema' => '/\bCREATE\s+SCHEMA\s+(?:IF\s+NOT\s+EXISTS\s+)?"?auth"?(?![\w$])/i',
        'auth object' => '/\bCREATE\s+(?:OR\s+REPLACE\s+)?(?:TABLE|TYPE|FUNCTION)\s+(?:IF\s+NOT\s+EXISTS\s+)?"?auth"?\s*\./i',
        'Supabase role' => '/\bCREATE\s+(?:ROLE|USER)\s+(?:IF\s+NOT\s+EXISTS\s+)?"?' . self::ROLES . '"?(?![\w$])/i',
        'auth table via the schema builder' => '/\bSchema::(?:create|table)\s*\(\s*[\'"]auth\./i',
    ];

    /**
     * @param list<string> $paths repo-relative paths; only those under MIGRATIONS_DIR are scanned
     * @param callable(string): string $read
     *
     * @return list<string>
     */
    public static function violations(array $paths, callable $read): array
    {
        if ($paths === []) {
            return ['the file listing is empty'];
        }

        $migrations = \array_values(\array_filter($paths, static fn(string $path): bool => \str_starts_with($path, self::MIGRATIONS_DIR)));

        if ($migrations === []) {
            return ['no migration in the listing'];
        }

        $violations = [];

        foreach ($migrations as $path) {
            $contents = $read($path);

            foreach (self::PATTERNS as $label => $pattern) {
                if (\preg_match($pattern, $contents) === 1) {
                    $violations[] = "{$label} created in {$path}";
                }
            }
        }

        return $violations;
    }
}
