<?php

declare(strict_types=1);

return [
    'project_token' => env('MIXPANEL_PROJECT_TOKEN'),
    'base_url' => env('MIXPANEL_BASE_URL', 'https://api.mixpanel.com'),
];
