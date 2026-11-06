<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Static\Support\TrackedFiles;
use Tests\Unit\Static\Support\TrackedLocalConfigProductionHostCheck;

/**
 * Tracked local config never points at production or carries a credential, and the phpunit configs force every
 * live-integration opt-in off.
 */
#[CoversNothing]
#[Group('repo-static')]
final class TrackedLocalConfigProductionHostTest extends TestCase
{
    #[Test]
    public function tracked_local_config_is_free_of_production_hosts_and_credentials(): void
    {
        $this->assertSame([], TrackedLocalConfigProductionHostCheck::violations(self::listing(), TrackedFiles::reader(\base_path())));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function plantedHosts(): iterable
    {
        yield 'railway.app' => ['.env.example', 'APP_URL=https://planted-production.up.railway.app', ['production host railway.app in .env.example']];
        yield 'railway.internal' => ['compose.yaml', '# REDIS_HOST: redis.railway.internal', ['production host railway.internal in compose.yaml']];
        yield 'rlwy.net' => ['config/database.php', "// 'host' => 'planted.proxy.rlwy.net',", ['production host rlwy.net in config/database.php']];
        yield 'supabase.co, any case' => ['phpunit-app.xml', '<!-- https://PLANTED.Supabase.Co -->', ['production host supabase.co in phpunit-app.xml']];
        yield 'supabase.com' => [
            '.env.example',
            'DB_HOST=aws-0-eu-west-2.pooler.supabase.com',
            ['production host supabase.co in .env.example', 'production host supabase.com in .env.example'],
        ];
        yield 'amazonaws.com' => ['config/filesystems.php', '// https://planted.s3.eu-west-2.amazonaws.com', ['production host amazonaws.com in config/filesystems.php']];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('plantedHosts')]
    public function a_planted_production_host_fails_the_check(string $path, string $line, array $expected): void
    {
        $reader = self::readerWith([$path => self::original($path) . "\n" . $line . "\n"]);

        $this->assertSame($expected, TrackedLocalConfigProductionHostCheck::violations(self::listing(), $reader));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function plantedCredentials(): iterable
    {
        // intentional: every fake credential is concatenated so this file does not trip secret scanners
        yield 'Slack token' => ['Slack token', 'xox' . 'b-0000000000-0000000000-planted'];
        yield 'Slack webhook' => ['Slack webhook', 'https://hooks.slack.com/' . 'services/T0000/B0000/planted'];
        yield 'AWS access key' => ['AWS access key', 'AKIA' . 'IOSFODNN7EXAMPLE'];
        yield 'Sentry DSN' => ['Sentry DSN', 'https://' . \str_repeat('0f', 16) . '@o0.ingest.example.test/1'];
        yield 'JWT' => ['JWT', 'eyJ' . 'hbGciOiJIUzI1NiJ9.eyJ' . 'zdWIiOiJwbGFudGVkIn0.signature'];
        yield 'private key' => ['private key', '-----BEGIN RSA ' . 'PRIVATE KEY-----'];
        yield 'Stripe live key' => ['Stripe live key', 'sk_' . 'live_planted'];
    }

    #[Test]
    #[DataProvider('plantedCredentials')]
    public function a_planted_credential_shaped_value_fails_the_check(string $label, string $value): void
    {
        $reader = self::readerWith(['.env.example' => self::original('.env.example') . "\nPLANTED_VALUE={$value}\n"]);

        $this->assertSame(
            ["credential-shaped value ({$label}) in .env.example"],
            TrackedLocalConfigProductionHostCheck::violations(self::listing(), $reader),
        );
    }

    #[Test]
    public function local_placeholder_credentials_pass_the_check(): void
    {
        $placeholders = "\nDB_PASSWORD=postgres\nHORIZON_PASSWORD=local-password\nSLACK_BOT_TOKEN=\nAWS_SECRET_ACCESS_KEY=\n";
        $reader = self::readerWith(['.env.example' => self::original('.env.example') . $placeholders]);

        $this->assertSame([], TrackedLocalConfigProductionHostCheck::violations(self::listing(), $reader));
    }

    #[Test]
    public function only_local_config_files_are_scanned(): void
    {
        $host = "\nDB_HOST=planted.railway.internal\n";
        $planted = ['.env.staging', 'config/nested/planted.php', 'docs/planted.md'];
        $reader = self::readerWith([
            TrackedLocalConfigProductionHostCheck::PRODUCTION_TEMPLATE => $host,
            '.env.staging' => $host,
            'config/nested/planted.php' => $host,
            'docs/planted.md' => $host,
        ]);

        $this->assertSame(
            ['production host railway.internal in .env.staging'],
            TrackedLocalConfigProductionHostCheck::violations([...self::listing(), ...$planted], $reader),
        );
    }

    #[Test]
    public function a_phpunit_config_missing_a_forced_flag_fails_the_check(): void
    {
        $contents = \str_replace('<env name="SLACK_LIVE" value="false" force="true"/>', '', self::original('phpunit-domain.xml'));

        $this->assertSame(
            ['phpunit-domain.xml is missing the SLACK_LIVE env'],
            TrackedLocalConfigProductionHostCheck::violations(self::listing(), self::readerWith(['phpunit-domain.xml' => $contents])),
        );
    }

    /**
     * @return iterable<string, array{string, string, string, list<string>}>
     */
    public static function rewrittenFlags(): iterable
    {
        yield 'force absent' => ['phpunit.xml', 'SENTRY_LIVE', '<env name="SENTRY_LIVE" value="false"/>', ['phpunit.xml does not force SENTRY_LIVE to false']];
        yield 'value true' => ['phpunit-app.xml', 'SUPABASE_LIVE', '<env force="true" value="true" name="SUPABASE_LIVE"/>', ['phpunit-app.xml does not force SUPABASE_LIVE to false']];
        yield 'reordered attributes' => ['phpunit-domain.xml', 'SHOPWIRED_LIVE', '<env force="true" name="SHOPWIRED_LIVE" value="false"/>', []];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('rewrittenFlags')]
    public function a_forced_flag_must_set_false_with_force(string $path, string $setting, string $replacement, array $expected): void
    {
        $contents = \str_replace("<env name=\"{$setting}\" value=\"false\" force=\"true\"/>", $replacement, self::original($path));

        $this->assertStringContainsString($replacement, $contents);
        $this->assertSame($expected, TrackedLocalConfigProductionHostCheck::violations(self::listing(), self::readerWith([$path => $contents])));
    }

    #[Test]
    public function a_malformed_phpunit_config_fails_the_check(): void
    {
        $this->assertSame(
            ['phpunit.xml is not well-formed XML'],
            TrackedLocalConfigProductionHostCheck::violations(self::listing(), self::readerWith(['phpunit.xml' => '<phpunit>'])),
        );
    }

    #[Test]
    public function an_empty_listing_fails_closed(): void
    {
        $this->assertSame(['the file listing is empty'], TrackedLocalConfigProductionHostCheck::violations([], static fn(string $path): string => ''));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredFiles(): iterable
    {
        foreach (TrackedLocalConfigProductionHostCheck::REQUIRED_FILES as $path) {
            yield $path => [$path];
        }
    }

    #[Test]
    #[DataProvider('requiredFiles')]
    public function a_listing_missing_a_required_file_fails_closed(string $missing): void
    {
        $listing = \array_values(\array_diff(self::listing(), [$missing]));

        $this->assertSame(
            ['required file missing from the listing: ' . $missing],
            TrackedLocalConfigProductionHostCheck::violations($listing, TrackedFiles::reader(\base_path())),
        );
    }

    /**
     * @return list<string>
     */
    private static function listing(): array
    {
        return TrackedFiles::list(\base_path());
    }

    private static function original(string $path): string
    {
        return TrackedFiles::reader(\base_path())($path);
    }

    /**
     * @param array<string, string> $contents planted contents per path
     *
     * @return callable(string): string serves `$contents` where planted and the work-tree file otherwise
     */
    private static function readerWith(array $contents): callable
    {
        $reader = TrackedFiles::reader(\base_path());

        return static fn(string $path): string => $contents[$path] ?? $reader($path);
    }
}
