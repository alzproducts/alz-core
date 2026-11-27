<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

/**
 * `database/baseline/versions.env` is the one place that names the Supabase image versions, and every consumer reads it (AC7, AC9).
 */
final class SupabaseImagePinCheck
{
    public const string PIN_FILE = 'database/baseline/versions.env';

    /**
     * @var array<string, non-empty-string> pin key => version shape
     */
    public const array PIN_SHAPES = [
        'SUPABASE_POSTGRES_VERSION' => '/^\d+(?:\.\d+){2,3}$/',
        'SUPABASE_AUTH_VERSION' => '/^v\d+\.\d+\.\d+$/',
        'SUPABASE_REALTIME_VERSION' => '/^v\d+\.\d+\.\d+$/',
    ];

    /**
     * @var array<string, list<string>> consumer => text it must contain to read the pin
     */
    public const array CONSUMERS = [
        '.github/workflows/ci.yml' => [self::PIN_FILE, 'SUPABASE_POSTGRES_VERSION', 'SUPABASE_AUTH_VERSION', 'SUPABASE_REALTIME_VERSION'],
        'database/baseline/compose.yaml' => ['supabase/postgres:${SUPABASE_POSTGRES_VERSION', 'supabase/auth:${SUPABASE_AUTH_VERSION', 'supabase/realtime:${SUPABASE_REALTIME_VERSION'],
        'Dockerfile.dev' => ['supabase/postgres:${SUPABASE_POSTGRES_VERSION}', 'supabase/auth:${SUPABASE_AUTH_VERSION}', 'supabase/realtime:${SUPABASE_REALTIME_VERSION}'],
        '.agents/skills/verify/verify' => [
            's/^SUPABASE_POSTGRES_VERSION=//p\' "$src/' . self::PIN_FILE,
            's/^SUPABASE_AUTH_VERSION=//p\' "$src/' . self::PIN_FILE,
        ],
        '.agents/skills/verify/compose.yaml' => ['supabase/postgres:${SUPABASE_POSTGRES_VERSION', 'supabase/auth:${SUPABASE_AUTH_VERSION'],
        'compose.local.yaml' => ['supabase/postgres:${SUPABASE_POSTGRES_VERSION', 'supabase/auth:${SUPABASE_AUTH_VERSION'],
        'bin/stack' => [
            's/^SUPABASE_POSTGRES_VERSION=//p\' "$REPO/' . self::PIN_FILE,
            's/^SUPABASE_AUTH_VERSION=//p\' "$REPO/' . self::PIN_FILE,
        ],
    ];

    /**
     * Paths never scanned: local AI workspaces, other worktrees, IDE state and this check's own planted fixtures.
     */
    public const array EXEMPT_PREFIXES = ['.ai/', '.claude/worktrees/', '.idea/', 'tests/Unit/Static/SupabaseImagePinTest.php'];

    private const string LITERAL_VERSION = '/(?:supabase\/(?:postgres|auth|realtime)|gotrue)(?:\s*[:@=]\s*|\s+)v?\d+\.\d+/i';

    /**
     * @param list<string> $paths repo-relative paths to scan
     * @param callable(string): string $read
     *
     * @return list<string>
     */
    public static function violations(array $paths, callable $read): array
    {
        if ($paths === []) {
            return ['the file listing is empty'];
        }

        $listed = \array_flip($paths);

        if (!isset($listed[self::PIN_FILE])) {
            return ['pin file missing from the listing: ' . self::PIN_FILE];
        }

        $violations = self::pinViolations($read(self::PIN_FILE));

        foreach (self::CONSUMERS as $path => $needles) {
            if (!isset($listed[$path])) {
                $violations[] = 'consumer missing from the listing: ' . $path;

                continue;
            }

            $contents = $read($path);

            foreach ($needles as $needle) {
                if (!\str_contains($contents, $needle)) {
                    $violations[] = "{$path} does not read the pin: {$needle}";
                }
            }
        }

        foreach ($paths as $path) {
            if ($path !== self::PIN_FILE && !self::isExempt($path) && \preg_match(self::LITERAL_VERSION, $read($path)) === 1) {
                $violations[] = 'Supabase image version named outside the pin file: ' . $path;
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private static function pinViolations(string $contents): array
    {
        $lines = \array_values(\array_filter(\explode("\n", $contents), static fn(string $line): bool => \mb_trim($line) !== ''));
        $pins = [];

        foreach ($lines as $line) {
            if (\preg_match('/^([A-Z_]+)=(\S+)$/', $line, $match) !== 1) {
                return ['pin file line is not KEY=VALUE: ' . $line];
            }

            $pins[$match[1]] = $match[2];
        }

        if (\count($lines) !== \count(self::PIN_SHAPES) || \array_keys($pins) !== \array_keys(self::PIN_SHAPES)) {
            return ['pin file must hold exactly: ' . \implode(', ', \array_keys(self::PIN_SHAPES))];
        }

        $violations = [];

        foreach (self::PIN_SHAPES as $key => $shape) {
            if (\preg_match($shape, $pins[$key]) !== 1) {
                $violations[] = "{$key} is not version-shaped: {$pins[$key]}";
            }
        }

        return $violations;
    }

    private static function isExempt(string $path): bool
    {
        return \array_any(self::EXEMPT_PREFIXES, static fn(string $prefix): bool => \str_starts_with($path, $prefix));
    }
}
