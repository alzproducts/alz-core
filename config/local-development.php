<?php

declare(strict_types=1);
use App\Application\Auth\TestUserPersonaResolver;

/**
 * Local Development Configuration.
 *
 * This file contains configuration specifically for local development,
 * including test user personas that map Supabase test emails to real
 * developer credentials for external service integrations.
 *
 * @see TestUserPersonaResolver
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Test User Personas
    |--------------------------------------------------------------------------
    |
    | Maps Supabase test emails (e.g., dev@alzadmin.test) to real developer
    | credentials. This is necessary because external services like HelpScout
    | require real email addresses, but local development uses test users.
    |
    | Security:
    | - Test emails are hardcoded (allow-list enforced)
    | - Real email comes from env var (not in version control)
    | - Roles/permissions are fixed per persona (not configurable via env)
    |
    | To add a new persona:
    | 1. Add the test email as a key below
    | 2. Set the email to the appropriate env var
    | 3. Assign a unique UUID (sequential: 00000000-0000-0000-0000-00000000000X)
    | 4. Configure is_approved, role_name, and departments as needed
    |
    */

    'test_user_personas' => [
        'dev@alzadmin.test' => [
            'email' => env('EMAIL_PRIMARY'),
            'user_id' => '00000000-0000-0000-0000-000000000001',
            'is_approved' => true,
            'role_name' => 'admin',
            'departments' => null,
        ],
        'default-guest@alzadmin.test' => [
            'email' => env('EMAIL_PRIMARY'), // Same developer, different persona
            'user_id' => '00000000-0000-0000-0000-000000000002',
            'is_approved' => true,
            'role_name' => 'guest',
            'departments' => null,
        ],
    ],

];
