<?php

declare(strict_types=1);

return [
    'base_url' => 'https://api.clickup.com/api/v2',

    'list_ids' => [
        'alz_products_team_tasks' => '901202940842',
    ],

    'complete_status' => env('CLICKUP_COMPLETE_STATUS', 'CLOSED'),

    'timeout_seconds' => 15,
];
