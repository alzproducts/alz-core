<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Attribution Window
    |--------------------------------------------------------------------------
    |
    | Time window (in hours) used to attribute an inbound call to a recent
    | tracking-number visit. Visits older than this window are ignored.
    |
    */

    'attribution_window_hours' => (int) env('CALL_TRACKING_ATTRIBUTION_WINDOW_HOURS', 6),

    /*
    |--------------------------------------------------------------------------
    | Default Business Phone Number
    |--------------------------------------------------------------------------
    |
    | E.164 number rendered when no tracking number can be assigned (e.g. no
    | active numbers in the pool, or marketing consent denied).
    |
    */

    'default_business_phone_number' => env('DEFAULT_BUSINESS_PHONE_NUMBER'),

    /*
    |--------------------------------------------------------------------------
    | Twilio Credentials
    |--------------------------------------------------------------------------
    |
    | Account auth token for the Twilio account that hosts the tracking-number
    | pool. Also signs inbound webhooks — Twilio's RequestValidator hashes the
    | request URL + params with this token (no separate webhook signing secret,
    | unlike Stripe).
    |
    */

    'twilio_auth_token' => env('TWILIO_AUTH_TOKEN'),
];
