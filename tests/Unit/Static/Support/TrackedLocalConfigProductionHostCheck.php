<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

use App\Infrastructure\Integrations\LiveIntegration;
use DOMDocument;
use DOMElement;

/**
 * Tracked local config names no production host and holds no credential-shaped value, and every phpunit config
 * forces each live-integration opt-in off.
 */
final class TrackedLocalConfigProductionHostCheck
{
    public const string PRODUCTION_TEMPLATE = '.env.production.example';

    public const array REQUIRED_FILES = ['.env.example', 'phpunit.xml', 'phpunit-app.xml', 'phpunit-domain.xml', 'compose.yaml'];

    public const array PHPUNIT_FILES = ['phpunit.xml', 'phpunit-app.xml', 'phpunit-domain.xml'];

    /**
     * Matched case-insensitively as substrings, so a `supabase.com` host also reports `supabase.co`.
     */
    public const array PRODUCTION_HOSTS = ['railway.app', 'railway.internal', 'rlwy.net', 'supabase.co', 'supabase.com', 'amazonaws.com'];

    // intentional: the webhook, private-key and Stripe literals are concatenated so this file does not trip secret scanners
    /**
     * Value shapes, not key names: local placeholders such as `DB_PASSWORD=postgres` stay legal.
     *
     * @var array<string, non-empty-string> label => regex
     */
    public const array CREDENTIAL_SHAPES = [
        'Slack token' => '/xox[abpr]-/',
        'Slack webhook' => '#hooks\.slack\.com/' . 'services/#i',
        'AWS access key' => '/AKIA[0-9A-Z]{16}/',
        'Sentry DSN' => '#https://[0-9a-f]{32}@#',
        'JWT' => '/eyJ[A-Za-z0-9_-]+\.eyJ/',
        'private key' => '/-----BEGIN [A-Z ]*PRIVATE' . ' KEY/',
        'Stripe live key' => '/sk_' . 'live_/',
    ];

    /**
     * @param list<string> $paths repo-relative paths
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
        $violations = [];

        foreach (self::REQUIRED_FILES as $path) {
            if (!isset($listed[$path])) {
                $violations[] = 'required file missing from the listing: ' . $path;
            }
        }

        foreach (\array_filter($paths, self::isScanned(...)) as $path) {
            \array_push($violations, ...self::contentViolations($path, $read($path)));
        }

        foreach (self::PHPUNIT_FILES as $path) {
            if (isset($listed[$path])) {
                \array_push($violations, ...self::forcedFlagViolations($path, $read($path)));
            }
        }

        return $violations;
    }

    private static function isScanned(string $path): bool
    {
        if (\str_starts_with(\basename($path), '.env')) {
            return \basename($path) !== self::PRODUCTION_TEMPLATE;
        }

        return \preg_match('#^(phpunit[^/]*\.xml|config/[^/]+\.php|compose\.yaml)$#', $path) === 1;
    }

    /**
     * @return list<string>
     */
    private static function contentViolations(string $path, string $contents): array
    {
        $lowered = \mb_strtolower($contents);
        $violations = [];

        foreach (self::PRODUCTION_HOSTS as $host) {
            if (\str_contains($lowered, $host)) {
                $violations[] = "production host {$host} in {$path}";
            }
        }

        foreach (self::CREDENTIAL_SHAPES as $label => $pattern) {
            if (\preg_match($pattern, $contents) === 1) {
                $violations[] = "credential-shaped value ({$label}) in {$path}";
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private static function forcedFlagViolations(string $path, string $contents): array
    {
        $document = new DOMDocument();
        $previous = \libxml_use_internal_errors(true);
        $loaded = $contents !== '' && $document->loadXML($contents, \LIBXML_NONET);
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [$path . ' is not well-formed XML'];
        }

        $envs = [];

        foreach ($document->getElementsByTagName('env') as $env) {
            $envs[$env->getAttribute('name')][] = $env;
        }

        $violations = [];

        foreach (LiveIntegration::cases() as $integration) {
            $setting = $integration->optInSetting();

            if (!isset($envs[$setting])) {
                $violations[] = "{$path} is missing the {$setting} env";
            } elseif (!\array_all($envs[$setting], self::forcesFalse(...))) {
                $violations[] = "{$path} does not force {$setting} to false";
            }
        }

        return $violations;
    }

    private static function forcesFalse(DOMElement $env): bool
    {
        return $env->getAttribute('value') === 'false' && $env->getAttribute('force') === 'true';
    }
}
