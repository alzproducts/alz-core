<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Static\Support\SupabaseImagePinCheck;
use Tests\Unit\Static\Support\TrackedFiles;

/**
 * The Supabase image versions live only in `database/baseline/versions.env`, and every consumer reads them (AC7, AC9).
 */
#[CoversNothing]
#[Group('repo-static')]
final class SupabaseImagePinTest extends TestCase
{
    private const string VALID_PIN = "SUPABASE_POSTGRES_VERSION=17.6.1.063\nSUPABASE_AUTH_VERSION=v2.188.1\nSUPABASE_REALTIME_VERSION=v2.178.1\n";

    #[Test]
    public function the_tree_names_supabase_versions_only_in_the_pin_file(): void
    {
        $root = \base_path();

        $this->assertSame([], SupabaseImagePinCheck::violations(TrackedFiles::list($root), TrackedFiles::reader($root)));
    }

    #[Test]
    public function a_valid_fixture_passes(): void
    {
        $this->assertSame([], self::check());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badPins(): iterable
    {
        yield 'extra key' => [self::VALID_PIN . "SUPABASE_STORAGE_VERSION=v1.0.0\n", 'pin file must hold exactly: SUPABASE_POSTGRES_VERSION, SUPABASE_AUTH_VERSION, SUPABASE_REALTIME_VERSION'];
        yield 'missing key' => ["SUPABASE_POSTGRES_VERSION=17.6.1.063\n", 'pin file must hold exactly: SUPABASE_POSTGRES_VERSION, SUPABASE_AUTH_VERSION, SUPABASE_REALTIME_VERSION'];
        yield 'keys reordered' => ["SUPABASE_AUTH_VERSION=v2.188.1\nSUPABASE_POSTGRES_VERSION=17.6.1.063\nSUPABASE_REALTIME_VERSION=v2.178.1\n", 'pin file must hold exactly: SUPABASE_POSTGRES_VERSION, SUPABASE_AUTH_VERSION, SUPABASE_REALTIME_VERSION'];
        yield 'comment line' => ["# pins\n" . self::VALID_PIN, 'pin file line is not KEY=VALUE: # pins'];
        yield 'postgres tag not version-shaped' => ["SUPABASE_POSTGRES_VERSION=latest\nSUPABASE_AUTH_VERSION=v2.188.1\nSUPABASE_REALTIME_VERSION=v2.178.1\n", 'SUPABASE_POSTGRES_VERSION is not version-shaped: latest'];
        yield 'auth tag without v' => ["SUPABASE_POSTGRES_VERSION=17.6.1.063\nSUPABASE_AUTH_VERSION=2.188.1\nSUPABASE_REALTIME_VERSION=v2.178.1\n", 'SUPABASE_AUTH_VERSION is not version-shaped: 2.188.1'];
        yield 'realtime tag without v' => ["SUPABASE_POSTGRES_VERSION=17.6.1.063\nSUPABASE_AUTH_VERSION=v2.188.1\nSUPABASE_REALTIME_VERSION=2.178.1\n", 'SUPABASE_REALTIME_VERSION is not version-shaped: 2.178.1'];
    }

    #[Test]
    #[DataProvider('badPins')]
    public function a_bad_pin_file_fails_the_check(string $pin, string $violation): void
    {
        $this->assertSame([$violation], self::check([SupabaseImagePinCheck::PIN_FILE => $pin]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function literalVersions(): iterable
    {
        yield 'image tag' => ['image: supabase/postgres:17.6.1.063'];
        yield 'auth image tag' => ['FROM supabase/auth:v2.188.1 AS auth'];
        yield 'realtime image tag' => ['image: supabase/realtime:v2.178.1'];
        yield 'gotrue prose' => ['local auth runs gotrue v2.188.1'];
        yield 'gotrue key' => ['gotrue=v2.188.1'];
    }

    #[Test]
    #[DataProvider('literalVersions')]
    public function a_version_named_outside_the_pin_file_fails_the_check(string $line): void
    {
        $this->assertSame(
            ['Supabase image version named outside the pin file: docs/notes.md'],
            self::check(['docs/notes.md' => "{$line}\n"]),
        );
    }

    #[Test]
    public function an_unversioned_mention_or_an_exempt_path_passes(): void
    {
        $this->assertSame([], self::check([
            'docs/notes.md' => "The baseline runs supabase/postgres and supabase/auth (gotrue).\n",
            '.ai/implementation-logs/x.md' => "supabase/postgres:17.6.1.063\n",
        ]));
    }

    #[Test]
    public function a_consumer_that_does_not_read_the_pin_fails_the_check(): void
    {
        $this->assertSame(
            ['Dockerfile.dev does not read the pin: supabase/auth:${SUPABASE_AUTH_VERSION}'],
            self::check(['Dockerfile.dev' => "ARG SUPABASE_POSTGRES_VERSION\nFROM supabase/postgres:\${SUPABASE_POSTGRES_VERSION}\nFROM supabase/auth:latest\n"]),
        );
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function verifyConsumers(): iterable
    {
        yield 'verify' => [
            '.agents/skills/verify/verify',
            "PG_VERSION=1\nAUTH_VERSION=1\nSOURCE_ERROR='database/baseline/versions.env lacks the Supabase pins'\n",
            ['s/^SUPABASE_POSTGRES_VERSION=//p\' "$src/database/baseline/versions.env', 's/^SUPABASE_AUTH_VERSION=//p\' "$src/database/baseline/versions.env'],
        ];
        yield 'compose.yaml' => [
            '.agents/skills/verify/compose.yaml',
            "FROM supabase/postgres:latest\nimage: supabase/auth:latest\n",
            ['supabase/postgres:${SUPABASE_POSTGRES_VERSION', 'supabase/auth:${SUPABASE_AUTH_VERSION'],
        ];
    }

    /**
     * @param list<string> $needles
     */
    #[Test]
    #[DataProvider('verifyConsumers')]
    public function a_verify_file_that_stops_reading_the_pin_fails_the_check(string $path, string $contents, array $needles): void
    {
        $this->assertSame(
            \array_map(static fn(string $needle): string => "{$path} does not read the pin: {$needle}", $needles),
            self::check([$path => $contents]),
        );
    }

    #[Test]
    public function a_consumer_missing_from_the_listing_fails_the_check(): void
    {
        $files = self::fixture();
        unset($files['.github/workflows/ci.yml']);

        $this->assertSame(
            ['consumer missing from the listing: .github/workflows/ci.yml'],
            SupabaseImagePinCheck::violations(\array_keys($files), static fn(string $path): string => $files[$path]),
        );
    }

    #[Test]
    public function an_empty_listing_fails_closed(): void
    {
        $this->assertSame(['the file listing is empty'], SupabaseImagePinCheck::violations([], static fn(string $path): string => ''));
    }

    #[Test]
    public function a_listing_without_the_pin_file_fails_closed(): void
    {
        $this->assertSame(
            ['pin file missing from the listing: ' . SupabaseImagePinCheck::PIN_FILE],
            SupabaseImagePinCheck::violations(['README.md'], static fn(string $path): string => ''),
        );
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return list<string>
     */
    private static function check(array $overrides = []): array
    {
        $files = [...self::fixture(), ...$overrides];

        return SupabaseImagePinCheck::violations(\array_keys($files), static fn(string $path): string => $files[$path]);
    }

    /**
     * @return array<string, string>
     */
    private static function fixture(): array
    {
        return [
            SupabaseImagePinCheck::PIN_FILE => self::VALID_PIN,
            '.github/workflows/ci.yml' => "env-file: database/baseline/versions.env\nSUPABASE_POSTGRES_VERSION\nSUPABASE_AUTH_VERSION\n",
            'database/baseline/compose.yaml' => "image: supabase/postgres:\${SUPABASE_POSTGRES_VERSION:?}\nimage: supabase/auth:\${SUPABASE_AUTH_VERSION:?}\nimage: supabase/realtime:\${SUPABASE_REALTIME_VERSION:?}\n",
            'Dockerfile.dev' => "FROM supabase/postgres:\${SUPABASE_POSTGRES_VERSION}\nFROM supabase/auth:\${SUPABASE_AUTH_VERSION}\n",
            '.agents/skills/verify/verify' => "PG_VERSION=\"\$(sed -n 's/^SUPABASE_POSTGRES_VERSION=//p' \"\$src/database/baseline/versions.env\")\"\nAUTH_VERSION=\"\$(sed -n 's/^SUPABASE_AUTH_VERSION=//p' \"\$src/database/baseline/versions.env\")\"\n",
            '.agents/skills/verify/compose.yaml' => "FROM supabase/postgres:\${SUPABASE_POSTGRES_VERSION:?}\nimage: supabase/auth:\${SUPABASE_AUTH_VERSION:?}\n",
            'compose.local.yaml' => "image: 'supabase/postgres:\${SUPABASE_POSTGRES_VERSION:?}'\nimage: 'supabase/auth:\${SUPABASE_AUTH_VERSION:?}'\n",
            'bin/stack' => "pg=\"\$(sed -n 's/^SUPABASE_POSTGRES_VERSION=//p' \"\$REPO/database/baseline/versions.env\")\"\nauth=\"\$(sed -n 's/^SUPABASE_AUTH_VERSION=//p' \"\$REPO/database/baseline/versions.env\")\"\n",
        ];
    }
}
