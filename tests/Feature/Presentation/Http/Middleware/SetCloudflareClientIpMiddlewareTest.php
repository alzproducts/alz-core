<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Middleware;

use App\Presentation\Http\Middleware\SetCloudflareClientIpMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SetCloudflareClientIpMiddleware Tests.
 *
 * A throwaway route echoes $request->ip() so each case asserts the IP the rest
 * of the app would observe. REMOTE_ADDR is set to a Cloudflare egress IP to
 * stand in for the proxy hop; the middleware should override it with the real
 * client only when CF-Connecting-IP carries a valid IP.
 */
#[CoversClass(SetCloudflareClientIpMiddleware::class)]
final class SetCloudflareClientIpMiddlewareTest extends TestCase
{
    /** A rotating Cloudflare egress IP — what Railway writes as REMOTE_ADDR. */
    private const string PROXY_IP = '162.158.88.167';

    private const string CLIENT_IPV4 = '138.199.60.181';

    private const string CLIENT_IPV6 = '2a02:6ea0:c024::17';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Route::get(
            '/_test/client-ip',
            static fn(Request $request): string => (string) $request->ip(),
        )->middleware(SetCloudflareClientIpMiddleware::class);
    }

    #[Test]
    public function it_uses_cf_connecting_ip_when_it_is_a_valid_ipv4(): void
    {
        $response = $this->callWithClientIp(self::CLIENT_IPV4);

        $response->assertOk();
        $response->assertContent(self::CLIENT_IPV4);
    }

    #[Test]
    public function it_uses_cf_connecting_ip_when_it_is_a_valid_ipv6(): void
    {
        $response = $this->callWithClientIp(self::CLIENT_IPV6);

        $response->assertOk();
        $response->assertContent(self::CLIENT_IPV6);
    }

    #[Test]
    public function it_falls_back_to_the_proxy_ip_when_the_header_is_absent(): void
    {
        $response = $this->callWithClientIp(null);

        $response->assertOk();
        $response->assertContent(self::PROXY_IP);
    }

    #[Test]
    public function it_ignores_a_malformed_header_and_falls_back(): void
    {
        $response = $this->callWithClientIp('not-an-ip-address');

        $response->assertOk();
        $response->assertContent(self::PROXY_IP);
    }

    #[Test]
    public function it_ignores_a_private_or_reserved_ip_and_falls_back(): void
    {
        // A genuine Cloudflare client is never a private/loopback address, so a
        // forged one (e.g. from a direct-to-origin request) must not be trusted.
        $response = $this->callWithClientIp('127.0.0.1');

        $response->assertOk();
        $response->assertContent(self::PROXY_IP);
    }

    #[Test]
    public function it_ignores_an_empty_header_and_falls_back(): void
    {
        $response = $this->callWithClientIp('');

        $response->assertOk();
        $response->assertContent(self::PROXY_IP);
    }

    #[Test]
    public function it_ignores_a_multi_value_header_and_falls_back(): void
    {
        $response = $this->callWithClientIp(self::CLIENT_IPV4 . ', ' . self::PROXY_IP);

        $response->assertOk();
        $response->assertContent(self::PROXY_IP);
    }

    /**
     * Hit the test route with a fixed proxy REMOTE_ADDR, optionally injecting a
     * CF-Connecting-IP header.
     *
     * @param string|null $cfConnectingIp Value for CF-Connecting-IP; null omits the header entirely
     */
    private function callWithClientIp(?string $cfConnectingIp): TestResponse
    {
        $server = ['REMOTE_ADDR' => self::PROXY_IP];

        if ($cfConnectingIp !== null) {
            $server['HTTP_CF_CONNECTING_IP'] = $cfConnectingIp;
        }

        return $this->call('GET', '/_test/client-ip', [], [], [], $server);
    }
}
