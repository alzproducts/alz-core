<?php

declare(strict_types=1);

namespace App\Infrastructure\Access;

use App\Domain\Exceptions\InvalidConfigurationException;

/**
 * Supabase Auth endpoints derived from the project URL (`SUPABASE_URL`).
 */
final readonly class SupabaseAuthConfig
{
    private const string CONFIG_KEY = 'services.supabase.url';

    private function __construct(
        public string $issuer,
        public string $jwksUrl,
    ) {}

    /**
     * @throws InvalidConfigurationException When the URL is empty, not https (http only when allowed),
     *                                       or not a bare origin (path, query, fragment or credentials)
     */
    public static function fromProjectUrl(string $url, bool $allowInsecureHttp): self
    {
        if ($url === '') {
            throw new InvalidConfigurationException(self::CONFIG_KEY, 'Supabase project URL is empty');
        }

        $parts = \parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidConfigurationException(self::CONFIG_KEY, 'Supabase project URL must be an absolute URL');
        }

        self::assertAllowedScheme($parts['scheme'], $allowInsecureHttp);
        self::assertBareOrigin($parts);

        $issuer = \mb_rtrim($url, '/') . '/auth/v1';

        return new self(issuer: $issuer, jwksUrl: $issuer . '/.well-known/jwks.json');
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function assertAllowedScheme(string $scheme, bool $allowInsecureHttp): void
    {
        if ($scheme === 'https' || ($scheme === 'http' && $allowInsecureHttp)) {
            return;
        }

        throw new InvalidConfigurationException(self::CONFIG_KEY, 'Supabase project URL must use https');
    }

    /**
     * @param array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string} $parts
     *
     * @throws InvalidConfigurationException
     */
    private static function assertBareOrigin(array $parts): void
    {
        $path = $parts['path'] ?? '';
        $hasExtraComponents = isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass']);

        if ($hasExtraComponents || ($path !== '' && $path !== '/')) {
            throw new InvalidConfigurationException(
                self::CONFIG_KEY,
                'Supabase project URL must be a bare origin without path, query or fragment',
            );
        }
    }
}
