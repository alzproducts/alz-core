<?php

declare(strict_types=1);

return [
    'base_url' => 'https://api.clickup.com/api/v2',

    'list_ids' => [
        'alz_products_team_tasks' => env('CLICKUP_LIST_ID_ALZ_PRODUCTS_TEAM_TASKS'),
    ],

    'complete_status' => env('CLICKUP_COMPLETE_STATUS', 'CLOSED'),

    'timeout_seconds' => 15,
];
