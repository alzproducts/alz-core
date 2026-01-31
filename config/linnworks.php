<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Linnworks API Credentials
    |--------------------------------------------------------------------------
    |
    | OAuth application credentials for the Linnworks API.
    | Get these from your Linnworks Developer Portal.
    |
    | Docs: https://apps.linnworks.net/Api
    |
    */

    'application_id' => env('LINNWORKS_APPLICATION_ID'),
    'application_secret' => env('LINNWORKS_APPLICATION_SECRET'),
    'installation_token' => env('LINNWORKS_INSTALLATION_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Request Configuration
    |--------------------------------------------------------------------------
    |
    | timeout: Maximum request timeout in seconds (1-300)
    | cache_ttl_buffer: Seconds to subtract from session TTL as safety margin
    |
    */

    'timeout' => 30,
    'cache_ttl_buffer' => 300,

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Optional API logging for debugging. Disabled by default (no overhead).
    |
    | Levels:
    |   - null (default): No logging decorator, zero overhead
    |   - 'info': Log endpoint, status, duration
    |   - 'debug': Log full request/response bodies (truncated to 1000 chars)
    |
    | Security: Auth tokens are never logged (added after decorator layer).
    |
    */

    'log_level' => env('LINNWORKS_LOG_LEVEL'),

];
