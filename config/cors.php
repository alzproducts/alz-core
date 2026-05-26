<?php

declare(strict_types=1);

/**
 * Cross-Origin Resource Sharing (CORS) Configuration.
 *
 * Enables cross-origin requests from the AlzProducts frontend to the public form endpoints.
 * Intentionally scoped to only the public endpoints - other API routes are same-origin.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
 */

return [
    // Enable CORS for public form/snapshot endpoints
    'paths' => ['api/contact', 'api/checkout/snapshot', 'api/display-number'],

    'allowed_methods' => ['POST', 'OPTIONS'],

    'allowed_origins' => array_filter(
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
        static fn(string $origin): bool => $origin !== '',
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'X-Requested-With'],

    'exposed_headers' => [],

    // Cache preflight for 24 hours (browser won't re-check OPTIONS)
    'max_age' => 86400,

    // No credentials needed for public form submission
    'supports_credentials' => false,
];
