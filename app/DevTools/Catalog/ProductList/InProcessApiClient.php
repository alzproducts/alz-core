<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Sends a consumer API request through the HTTP kernel in this process, as the local-bypass approved user.
 */
final readonly class InProcessApiClient
{
    public function __construct(
        private Kernel $kernel,
        private Repository $config,
    ) {}

    /**
     * The response body exactly as the endpoint returns it.
     *
     * @throws RuntimeException When the endpoint does not answer 200
     */
    public function get(string $uri): string
    {
        $request = Request::create($uri, 'GET', server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_LOCAL_BYPASS' => $this->config->get('services.supabase.local_bypass_secret'),
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $response = $this->kernel->handle($request);
        $body = $response->getContent();

        if ($response->getStatusCode() !== 200 || $body === false) {
            throw new RuntimeException("GET {$uri} answered {$response->getStatusCode()} instead of 200.");
        }

        return $body;
    }
}
