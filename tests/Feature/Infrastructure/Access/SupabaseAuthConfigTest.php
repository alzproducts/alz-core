<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Access;

use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Access\SupabaseAuthConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SupabaseAuthConfig::class)]
final class SupabaseAuthConfigTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentOrigins(): iterable
    {
        yield 'without trailing slash' => ['https://x.supabase.co'];
        yield 'with trailing slash' => ['https://x.supabase.co/'];
    }

    #[Test]
    #[DataProvider('equivalentOrigins')]
    public function it_derives_the_issuer_and_jwks_url_from_the_origin(string $url): void
    {
        $config = SupabaseAuthConfig::fromProjectUrl($url, allowInsecureHttp: false);

        $this->assertSame('https://x.supabase.co/auth/v1', $config->issuer);
        $this->assertSame('https://x.supabase.co/auth/v1/.well-known/jwks.json', $config->jwksUrl);
    }

    #[Test]
    public function it_accepts_http_with_a_port_when_insecure_http_is_allowed(): void
    {
        $config = SupabaseAuthConfig::fromProjectUrl('http://127.0.0.1:54321', allowInsecureHttp: true);

        $this->assertSame('http://127.0.0.1:54321/auth/v1', $config->issuer);
        $this->assertSame('http://127.0.0.1:54321/auth/v1/.well-known/jwks.json', $config->jwksUrl);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'http' => ['http://x.supabase.co'];
        yield 'other scheme' => ['ftp://x.supabase.co'];
        yield 'not absolute' => ['x.supabase.co'];
        yield 'path' => ['https://x.supabase.co/auth/v1'];
        yield 'query' => ['https://x.supabase.co/?ref=abc'];
        yield 'fragment' => ['https://x.supabase.co/#top'];
        yield 'credentials' => ['https://user:pass@x.supabase.co'];
    }

    #[Test]
    #[DataProvider('invalidUrls')]
    public function it_rejects_an_invalid_project_url(string $url): void
    {
        try {
            SupabaseAuthConfig::fromProjectUrl($url, allowInsecureHttp: false);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertSame('services.supabase.url', $e->configKey);
        }
    }

    #[Test]
    public function it_still_rejects_a_path_when_insecure_http_is_allowed(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        SupabaseAuthConfig::fromProjectUrl('http://127.0.0.1:54321/auth/v1', allowInsecureHttp: true);
    }
}
