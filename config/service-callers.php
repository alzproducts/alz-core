<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Service Callers
    |--------------------------------------------------------------------------
    |
    | One entry per service caller, read by AuthenticateServiceCallerMiddleware.
    | A blank value refuses that caller's requests (docs/adr/0023).
    |
    | token_sha256: hex SHA-256 of the caller's bearer token, never the token.
    | allowed_ips: comma-separated client IP addresses or CIDR ranges.
    |
    */

    'alz_connect' => [
        'token_sha256' => env('ALZ_CONNECT_BEARER_SHA256'),
        'allowed_ips' => env('ALZ_CONNECT_ALLOWED_IPS'),
    ],
];
